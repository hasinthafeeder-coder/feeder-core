<?php

namespace Feeder\Core\Services\Order;

use Feeder\Core\Enums\ApplicationType;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\OrderCommentContextType;
use Feeder\Core\Enums\OrderPaymentMethod;
use Feeder\Core\Enums\OrderPaymentReviewStatus;
use Feeder\Core\Enums\OrderStatus;
use Feeder\Core\Models\File;
use Feeder\Core\Models\Order;
use Feeder\Core\Models\OrderPaymentSubmission;
use Feeder\Core\Models\User;
use Feeder\Core\Services\FileService;
use Feeder\Core\Services\UuidService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bank-transfer payment submission review lifecycle.
 *
 * Payment review state lives on order_payment_submissions, not OrderStatus.
 * Approval books the draft courier (when needed), then confirms via
 * OrderStatusService::transition(CONFIRMED) only after a successful waybill.
 */
class OrderPaymentReviewService
{
    public const PERMISSION_REVIEW = 'orders.payment.review';

    public const PERMISSION_APPROVE = 'orders.payment.approve';

    public const PERMISSION_REJECT = 'orders.payment.reject';

    public function __construct(
        private readonly OrderAuthorizationGuard $authorizationGuard,
        private readonly OrderCourierLookupService $courierLookupService,
        private readonly OrderStatusService $statusService,
        private readonly OrderCommentService $commentService,
        private readonly FileService $fileService,
        private readonly DraftOrderCourierBookingService $draftCourierBookingService,
    ) {
    }

    public function hasPendingApproval(Order $order): bool
    {
        return $order->hasPendingPaymentApproval();
    }

    /**
     * Whether reseller-facing order mutations are allowed (edit / status / book).
     */
    public function canEditOrder(Order $order): bool
    {
        if ($order->isCancelled()) {
            return false;
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if (($status !== null && $status->locksDiscount()) || $order->confirmed_at !== null) {
            return false;
        }

        return ! $this->hasPendingApproval($order);
    }

    public function assertNotPendingApproval(Order $order): void
    {
        if ($this->hasPendingApproval($order)) {
            throw ValidationException::withMessages([
                'order' => [
                    'This order is locked while a bank transfer payment is pending approval.',
                ],
            ]);
        }
    }

    /**
     * @param  int|null  $actorCompanyId  Reseller company boundary for submitter.
     */
    public function submitBankTransfer(
        Order $order,
        User $actor,
        UploadedFile $slip,
        string $referenceNumber,
        float|string $amount,
        string $description,
        ?int $actorCompanyId = null,
    ): OrderPaymentSubmission {
        if ($actorCompanyId !== null) {
            $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
        }

        $this->assertCanSubmit($order);
        $this->assertCourierReadyForBooking($order);

        // Upload before the DB write so a failed store never leaves a pending row.
        $uploaded = $this->uploadPaymentProof($order, $actor, $slip);

        return DB::transaction(function () use (
            $order,
            $actor,
            $slip,
            $referenceNumber,
            $amount,
            $description,
            $actorCompanyId,
            $uploaded,
        ) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($actorCompanyId !== null) {
                $this->authorizationGuard->assertOrderCompany($order, $actorCompanyId);
            }

            $this->assertCanSubmit($order);
            $this->assertCourierReadyForBooking($order);

            $submission = OrderPaymentSubmission::query()->create([
                'order_id' => (int) $order->id,
                'method' => OrderPaymentMethod::BANK_TRANSFER,
                'review_status' => OrderPaymentReviewStatus::PENDING_REVIEW,
                'reference_number' => trim($referenceNumber),
                'amount' => $amount,
                'description' => trim($description),
                'slip_file_uuid' => $uploaded['uuid'],
                'slip_original_name' => $uploaded['original_name'] ?? $slip->getClientOriginalName(),
                'submitted_by_user_id' => (int) $actor->id,
                'submitted_by_company_id' => (int) ($actorCompanyId ?? $actor->company_id),
                'submitted_at' => now(),
            ]);

            $this->attachSubmissionMetadataToProofFile($uploaded['uuid'], $submission, $order);

            $this->commentService->add(
                $order,
                'Bank transfer payment approval requested',
                OrderCommentContextType::SYSTEM,
                (int) $actor->id,
                (int) ($actorCompanyId ?? $actor->company_id),
                $submission->uuid,
            );

            return $submission->fresh();
        });
    }

    /**
     * Portal file-proxy gate for PAYMENT_PROOF.
     *
     * Non-payment files are left to existing auth-only proxy behaviour.
     * Payment proofs require company ownership (reseller) or payment-review permission (admin).
     */
    public function authorizeFileAccessIfPaymentProof(User $actor, string $fileUuid): void
    {
        $fileUuid = strtoupper(trim($fileUuid));

        if ($fileUuid === '') {
            abort(404);
        }

        $submission = OrderPaymentSubmission::query()
            ->where('slip_file_uuid', $fileUuid)
            ->first();

        $file = File::query()->where('uuid', $fileUuid)->first();
        $isPaymentProof = $submission !== null
            || ($file !== null && (string) $file->category === FileCategory::PAYMENT_PROOF->value);

        if (! $isPaymentProof) {
            return;
        }

        if ($submission === null) {
            abort(403);
        }

        $this->assertActorMayAccessSubmission($actor, $submission);
    }

    public function assertActorMayAccessSubmission(User $actor, OrderPaymentSubmission $submission): void
    {
        if ($actor->hasAnyPermission([
            self::PERMISSION_REVIEW,
            self::PERMISSION_APPROVE,
            self::PERMISSION_REJECT,
        ])) {
            return;
        }

        $order = Order::query()->find($submission->order_id);

        if ($order === null || $actor->company_id === null) {
            abort(403);
        }

        try {
            $this->authorizationGuard->assertOrderCompany($order, (int) $actor->company_id);
        } catch (ValidationException) {
            abort(403);
        }
    }

    public function approve(OrderPaymentSubmission $submission, User $adminActor): OrderPaymentSubmission
    {
        $this->assertActorHasPermission($adminActor, self::PERMISSION_APPROVE);

        $orderId = (int) DB::transaction(function () use ($submission, $adminActor) {
            $submission = OrderPaymentSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->id);

            $this->assertCanApprove($submission);

            $order = Order::query()->lockForUpdate()->findOrFail($submission->order_id);
            $this->assertOrderEligibleForApproval($order);
            $order->loadMissing('shipment');

            if ($order->shipment === null && ! $order->hasBookedShipment()) {
                $this->assertCourierReadyForBooking($order);
            }

            // Clear the pending-payment lock before courier booking.
            $submission->update([
                'review_status' => OrderPaymentReviewStatus::APPROVED,
                'reviewed_by_user_id' => (int) $adminActor->id,
                'reviewed_at' => now(),
            ]);

            return (int) $order->id;
        });

        $order = Order::query()->with('shipment')->findOrFail($orderId);

        if ($order->shipment === null && ! $order->hasBookedShipment()) {
            try {
                $this->draftCourierBookingService->book(
                    $order,
                    (int) $order->draft_courier_id,
                    $order->draft_courier_service_id !== null ? (int) $order->draft_courier_service_id : null,
                    (int) $order->draft_courier_city_id,
                    (int) $adminActor->id,
                    null,
                );
            } catch (Throwable $exception) {
                try {
                    $this->draftCourierBookingService->recordFailure($order, $exception, (int) $adminActor->id);
                } catch (Throwable $recordException) {
                    report($recordException);
                }

                DB::transaction(function () use ($submission): void {
                    $submission = OrderPaymentSubmission::query()
                        ->lockForUpdate()
                        ->findOrFail($submission->id);

                    if ($submission->review_status === OrderPaymentReviewStatus::APPROVED
                        || (string) $submission->review_status === OrderPaymentReviewStatus::APPROVED->value
                    ) {
                        $submission->update([
                            'review_status' => OrderPaymentReviewStatus::PENDING_REVIEW,
                            'reviewed_by_user_id' => null,
                            'reviewed_at' => null,
                        ]);
                    }
                });

                if ($exception instanceof ValidationException) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'booking' => [
                        'Courier booking failed. Payment was not approved and the order was not confirmed.',
                    ],
                ]);
            }
        }

        return DB::transaction(function () use ($submission, $adminActor) {
            $submission = OrderPaymentSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->id);

            $order = Order::query()->lockForUpdate()->findOrFail($submission->order_id);
            $this->assertOrderEligibleForApproval($order);
            $order->loadMissing('shipment');

            if ($order->shipment === null && ! $order->hasBookedShipment()) {
                throw ValidationException::withMessages([
                    'booking' => [
                        'Courier booking did not produce a shipment. Payment was not confirmed.',
                    ],
                ]);
            }

            $status = $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::tryFrom((string) $order->status);

            if ($status !== OrderStatus::CONFIRMED) {
                $this->statusService->transition(
                    $order,
                    OrderStatus::CONFIRMED,
                    (int) $adminActor->id,
                    null,
                    'Bank transfer payment approved',
                );
            }

            $this->commentService->add(
                $order,
                'Bank transfer payment approved',
                OrderCommentContextType::SYSTEM,
                (int) $adminActor->id,
                null,
                $submission->uuid,
            );

            return $submission->fresh(['order']);
        });
    }

    public function reject(
        OrderPaymentSubmission $submission,
        User $adminActor,
        string $reason,
    ): OrderPaymentSubmission {
        $this->assertActorHasPermission($adminActor, self::PERMISSION_REJECT);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'review_note' => ['A rejection reason is required.'],
            ]);
        }

        return DB::transaction(function () use ($submission, $adminActor, $reason) {
            $submission = OrderPaymentSubmission::query()
                ->lockForUpdate()
                ->findOrFail($submission->id);

            $this->assertCanReject($submission);

            $order = Order::query()->lockForUpdate()->findOrFail($submission->order_id);

            $submission->update([
                'review_status' => OrderPaymentReviewStatus::REJECTED,
                'reviewed_by_user_id' => (int) $adminActor->id,
                'reviewed_at' => now(),
                'review_note' => $reason,
            ]);

            $this->commentService->add(
                $order,
                'Bank transfer payment rejected',
                OrderCommentContextType::SYSTEM,
                (int) $adminActor->id,
                null,
                $submission->uuid,
            );

            return $submission->fresh(['order']);
        });
    }

    public function assertCanSubmit(Order $order): void
    {
        if ($order->isCancelled()) {
            throw ValidationException::withMessages([
                'order' => ['Cancelled orders cannot accept bank transfer submissions.'],
            ]);
        }

        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::tryFrom((string) $order->status);

        if (($status !== null && $status->locksDiscount()) || $order->confirmed_at !== null) {
            throw ValidationException::withMessages([
                'order' => ['Confirmed orders cannot accept bank transfer submissions.'],
            ]);
        }

        if ($this->hasPendingApproval($order)) {
            throw ValidationException::withMessages([
                'payment' => [
                    'A bank transfer submission is already pending approval for this order.',
                ],
            ]);
        }

        $hasApproved = OrderPaymentSubmission::query()
            ->where('order_id', $order->id)
            ->where('method', OrderPaymentMethod::BANK_TRANSFER)
            ->where('review_status', OrderPaymentReviewStatus::APPROVED)
            ->exists();

        if ($hasApproved) {
            throw ValidationException::withMessages([
                'payment' => [
                    'This order already has an approved bank transfer submission.',
                ],
            ]);
        }
    }

    public function assertCanApprove(OrderPaymentSubmission $submission): void
    {
        $this->assertBankTransferSubmission($submission);

        if ($submission->review_status !== OrderPaymentReviewStatus::PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'payment' => [
                    'Only bank transfer submissions pending review can be approved.',
                ],
            ]);
        }
    }

    public function assertCanReject(OrderPaymentSubmission $submission): void
    {
        $this->assertBankTransferSubmission($submission);

        if ($submission->review_status !== OrderPaymentReviewStatus::PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'payment' => [
                    'Only bank transfer submissions pending review can be rejected.',
                ],
            ]);
        }
    }

    /**
     * Validate draft courier assignment is complete and bookable (does not book).
     */
    public function assertCourierReadyForBooking(Order $order): void
    {
        if ($order->draft_courier_id === null) {
            throw ValidationException::withMessages([
                'courier_id' => [
                    'A courier must be saved before this order can be confirmed.',
                ],
            ]);
        }

        if ($order->draft_courier_service_id === null) {
            throw ValidationException::withMessages([
                'courier_service_id' => [
                    'A courier service must be saved before this order can be confirmed.',
                ],
            ]);
        }

        if ($order->draft_courier_city_id === null) {
            throw ValidationException::withMessages([
                'courier_city_id' => [
                    'A courier city must be saved before this order can be confirmed.',
                ],
            ]);
        }

        $courierId = (int) $order->draft_courier_id;
        $serviceId = (int) $order->draft_courier_service_id;
        $cityId = (int) $order->draft_courier_city_id;

        $courier = $this->courierLookupService->requireEligibleCourier($order, $courierId);
        $this->courierLookupService->requireServiceForCourier($courierId, $serviceId);
        $this->courierLookupService->requireEligibleCity($order, $courierId, $cityId);
        $this->courierLookupService->requireSupplierAccount($order, $courier);
        $this->courierLookupService->requireMarketPricing($order, $courier);
    }

    private function assertBankTransferSubmission(OrderPaymentSubmission $submission): void
    {
        $method = $submission->method instanceof OrderPaymentMethod
            ? $submission->method
            : OrderPaymentMethod::tryFrom((string) $submission->method);

        if ($method !== OrderPaymentMethod::BANK_TRANSFER) {
            throw ValidationException::withMessages([
                'payment' => ['Only bank transfer submissions can be reviewed.'],
            ]);
        }
    }

    private function assertOrderEligibleForApproval(Order $order): void
    {
        if ($order->isCancelled()) {
            throw ValidationException::withMessages([
                'order' => ['Cancelled orders cannot have bank transfer payments approved.'],
            ]);
        }
    }

    private function assertActorHasPermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw ValidationException::withMessages([
                'payment' => ['You are not authorized to perform this payment review action.'],
            ]);
        }
    }

    /**
     * @return array{uuid: string, original_name: ?string}
     */
    private function uploadPaymentProof(Order $order, User $actor, UploadedFile $slip): array
    {
        $order->loadMissing('resellerCompany');

        $entityUuid = $this->resolveFeederFilesEntityUuid($order->resellerCompany?->uuid);
        $uploadedBy = $this->resolveFeederFilesActorUuid($actor->uuid);

        try {
            $response = $this->fileService->upload(
                $slip,
                ApplicationType::RESELLER->value,
                'ORDER_PAYMENT',
                $entityUuid,
                FileCategory::PAYMENT_PROOF->value,
                $uploadedBy,
                [
                    'order_uuid' => $order->uuid,
                    'order_id' => (int) $order->id,
                ],
            );
        } catch (RequestException $e) {
            report($e);

            throw ValidationException::withMessages([
                'payment_slip' => ['Unable to store the payment slip. Try again.'],
            ]);
        } catch (ConnectionException|Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'payment_slip' => ['Unable to store the payment slip. Try again.'],
            ]);
        }

        $uuid = data_get($response, 'file.uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw ValidationException::withMessages([
                'payment_slip' => ['Unable to store the payment slip. Try again.'],
            ]);
        }

        return [
            'uuid' => strtoupper($uuid),
            'original_name' => data_get($response, 'file.original_name'),
        ];
    }

    /**
     * feeder-files entity_uuid must be exactly 10 characters.
     * Prefer native 10-char company UUIDs; derive a stable key from longer UUIDs.
     */
    private function resolveFeederFilesEntityUuid(?string $companyUuid): string
    {
        $companyUuid = is_string($companyUuid) ? trim($companyUuid) : '';

        if (strlen($companyUuid) === 10) {
            return strtoupper($companyUuid);
        }

        if ($companyUuid !== '') {
            $compact = strtoupper(str_replace('-', '', $companyUuid));

            if (strlen($compact) >= 10) {
                return substr($compact, 0, 10);
            }
        }

        return UuidService::generate();
    }

    /**
     * feeder-files uploaded_by is optional but must be exactly 10 characters when present.
     */
    private function resolveFeederFilesActorUuid(?string $actorUuid): ?string
    {
        $actorUuid = is_string($actorUuid) ? trim($actorUuid) : '';

        if (strlen($actorUuid) === 10) {
            return strtoupper($actorUuid);
        }

        return null;
    }

    private function attachSubmissionMetadataToProofFile(
        string $fileUuid,
        OrderPaymentSubmission $submission,
        Order $order,
    ): void {
        $file = File::query()->where('uuid', strtoupper($fileUuid))->first();

        if ($file === null) {
            // Shared DB may not see the row when feeder-files is remote/faked in tests.
            return;
        }

        $metadata = is_array($file->metadata) ? $file->metadata : [];

        $file->forceFill([
            'metadata' => array_merge($metadata, [
                'order_uuid' => $order->uuid,
                'order_id' => (int) $order->id,
                'payment_submission_uuid' => $submission->uuid,
                'payment_submission_id' => (int) $submission->id,
            ]),
        ])->save();
    }
}
