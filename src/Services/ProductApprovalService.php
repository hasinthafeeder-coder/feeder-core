<?php

namespace Feeder\Core\Services;

use Feeder\Core\Enums\ProductApprovalStatus;
use Feeder\Core\Enums\ProductStatus;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductApprovalLog;
use Feeder\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductApprovalService
{
    public const PERMISSION_REVIEW = 'products.review';

    public const PERMISSION_APPROVE = 'products.approve';

    public const PERMISSION_REJECT = 'products.reject';

    /**
     * Supplier submits (or resubmits) a product creation for admin review.
     *
     * Keeps lifecycle status as DRAFT so the product cannot become reseller-visible
     * until an admin approves it into ACTIVE + APPROVED.
     */
    public function submitForReview(Product $product, int $submittedBy): Product
    {
        if ($product->hasPendingEditChanges()) {
            throw ValidationException::withMessages([
                'product' => ['This product already has pending edit changes awaiting review.'],
            ]);
        }

        if ($product->approval_status === ProductApprovalStatus::APPROVED
            && $product->status === ProductStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'product' => ['This product is already approved and active. Submit an edit review instead.'],
            ]);
        }

        if ($product->approval_status === ProductApprovalStatus::PENDING_REVIEW) {
            // Allow content refresh / explicit resubmit while already pending (create flow).
            return DB::transaction(function () use ($product, $submittedBy): Product {
                $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

                if ($locked->hasPendingEditChanges()) {
                    throw ValidationException::withMessages([
                        'product' => ['This product already has pending edit changes awaiting review.'],
                    ]);
                }

                $locked->fill([
                    'status' => ProductStatus::DRAFT,
                    'approval_status' => ProductApprovalStatus::PENDING_REVIEW,
                    'submitted_for_review_at' => now(),
                    'updated_by' => $submittedBy,
                    'rejection_reason' => null,
                    'rejected_at' => null,
                    'rejected_by' => null,
                    'pending_changes' => null,
                ]);
                $locked->save();

                $this->writeLog(
                    $locked,
                    ProductApprovalLog::ACTION_SUBMITTED,
                    $submittedBy,
                    'Resubmitted for review.',
                    ProductApprovalLog::SUBMISSION_TYPE_CREATE,
                );

                return $locked->refresh()->load($this->defaultRelations());
            });
        }

        return DB::transaction(function () use ($product, $submittedBy): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status === ProductApprovalStatus::APPROVED
                && $locked->status === ProductStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'product' => ['This product is already approved and active. Submit an edit review instead.'],
                ]);
            }

            if ($locked->approval_status === ProductApprovalStatus::PENDING_REVIEW) {
                throw ValidationException::withMessages([
                    'product' => ['This product is already pending review.'],
                ]);
            }

            $locked->fill([
                'status' => ProductStatus::DRAFT,
                'approval_status' => ProductApprovalStatus::PENDING_REVIEW,
                'submitted_for_review_at' => now(),
                'updated_by' => $submittedBy,
                'approved_at' => null,
                'approved_by' => null,
                'rejection_reason' => null,
                'rejected_at' => null,
                'rejected_by' => null,
                'pending_changes' => null,
            ]);
            $locked->save();

            $this->writeLog(
                $locked,
                ProductApprovalLog::ACTION_SUBMITTED,
                $submittedBy,
                'Submitted for admin review.',
                ProductApprovalLog::SUBMISSION_TYPE_CREATE,
            );

            return $locked->refresh()->load($this->defaultRelations());
        });
    }

    /**
     * Supplier submits edits against an already-approved product.
     *
     * Live approved catalog fields stay unchanged until an admin approves the payload.
     *
     * @param  array{
     *     attributes?: array<string, mixed>,
     *     descriptions?: array<int, array<string, mixed>>,
     *     variants?: array<int, array<string, mixed>>,
     *     images?: array<int, array<string, mixed>>,
     *     guideline?: array<string, mixed>|null
     * }  $changes
     */
    public function submitEditForReview(Product $product, array $changes, int $submittedBy): Product
    {
        if ($product->approval_status !== ProductApprovalStatus::APPROVED) {
            throw ValidationException::withMessages([
                'product' => ['Only approved products can submit edit reviews.'],
            ]);
        }

        return DB::transaction(function () use ($product, $changes, $submittedBy): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if (
                $locked->approval_status !== ProductApprovalStatus::APPROVED
                || $locked->hasPendingEditChanges()
            ) {
                throw ValidationException::withMessages([
                    'product' => ['This product already has changes pending admin review or is not approved for edit submission.'],
                ]);
            }

            $payload = $this->normalizeEditPayload($changes);

            $locked->fill([
                // Keep lifecycle ACTIVE so reseller availability is preserved.
                'status' => ProductStatus::ACTIVE,
                'approval_status' => ProductApprovalStatus::PENDING_REVIEW,
                'submitted_for_review_at' => now(),
                'updated_by' => $submittedBy,
                'pending_changes' => $payload,
                'rejection_reason' => null,
                'rejected_at' => null,
                'rejected_by' => null,
            ]);
            $locked->save();

            $this->writeLog(
                $locked,
                ProductApprovalLog::ACTION_SUBMITTED,
                $submittedBy,
                'Submitted product edits for admin review.',
                ProductApprovalLog::SUBMISSION_TYPE_EDIT,
                $payload,
            );

            return $locked->refresh()->load($this->defaultRelations());
        });
    }

    public function approve(Product $product, User $admin): Product
    {
        $this->assertActorHasPermission($admin, self::PERMISSION_APPROVE);

        if ($product->approval_status !== ProductApprovalStatus::PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'product' => ['Only products pending review can be approved.'],
            ]);
        }

        return DB::transaction(function () use ($product, $admin): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== ProductApprovalStatus::PENDING_REVIEW) {
                throw ValidationException::withMessages([
                    'product' => ['Only products pending review can be approved.'],
                ]);
            }

            $isEditReview = $locked->hasPendingEditChanges();
            $pendingChanges = $locked->pending_changes;

            if ($isEditReview) {
                $locked->fill([
                    'pending_changes' => null,
                    'approval_status' => ProductApprovalStatus::APPROVED,
                    'status' => ProductStatus::ACTIVE,
                    'approved_at' => now(),
                    'approved_by' => $admin->id,
                    'rejected_at' => null,
                    'rejected_by' => null,
                    'rejection_reason' => null,
                    'updated_by' => $admin->id,
                ]);
                $locked->save();

                $this->applyPendingEditChanges($locked, is_array($pendingChanges) ? $pendingChanges : [], (int) $admin->id);

                $this->writeLog(
                    $locked,
                    ProductApprovalLog::ACTION_APPROVED,
                    (int) $admin->id,
                    'Approved product edits.',
                    ProductApprovalLog::SUBMISSION_TYPE_EDIT,
                    is_array($pendingChanges) ? $pendingChanges : null,
                );

                return $locked->refresh()->load($this->defaultRelations());
            }

            $locked->fill([
                'status' => ProductStatus::ACTIVE,
                'approval_status' => ProductApprovalStatus::APPROVED,
                'published_at' => $locked->published_at ?? now(),
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'pending_changes' => null,
                'updated_by' => $admin->id,
            ]);
            $locked->save();

            $this->writeLog(
                $locked,
                ProductApprovalLog::ACTION_APPROVED,
                (int) $admin->id,
                null,
                ProductApprovalLog::SUBMISSION_TYPE_CREATE,
            );

            return $locked->refresh()->load($this->defaultRelations());
        });
    }

    public function reject(Product $product, User $admin, string $reason): Product
    {
        $this->assertActorHasPermission($admin, self::PERMISSION_REJECT);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Please provide a meaningful rejection remark (at least 5 characters).'],
            ]);
        }

        if ($product->approval_status !== ProductApprovalStatus::PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'product' => ['Only products pending review can be rejected.'],
            ]);
        }

        return DB::transaction(function () use ($product, $admin, $reason): Product {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->approval_status !== ProductApprovalStatus::PENDING_REVIEW) {
                throw ValidationException::withMessages([
                    'product' => ['Only products pending review can be rejected.'],
                ]);
            }

            $isEditReview = $locked->hasPendingEditChanges();
            $pendingChanges = $locked->pending_changes;

            if ($isEditReview) {
                // Preserve live approved catalog; discard unpublished edit payload.
                $locked->fill([
                    'status' => ProductStatus::ACTIVE,
                    'approval_status' => ProductApprovalStatus::APPROVED,
                    'pending_changes' => null,
                    'rejected_at' => now(),
                    'rejected_by' => $admin->id,
                    'rejection_reason' => $reason,
                    'updated_by' => $admin->id,
                ]);
                $locked->save();

                $this->writeLog(
                    $locked,
                    ProductApprovalLog::ACTION_REJECTED,
                    (int) $admin->id,
                    $reason,
                    ProductApprovalLog::SUBMISSION_TYPE_EDIT,
                    is_array($pendingChanges) ? $pendingChanges : null,
                );

                return $locked->refresh()->load($this->defaultRelations());
            }

            $locked->fill([
                'status' => ProductStatus::DRAFT,
                'approval_status' => ProductApprovalStatus::REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $admin->id,
                'rejection_reason' => $reason,
                'pending_changes' => null,
                'updated_by' => $admin->id,
            ]);
            $locked->save();

            $this->writeLog(
                $locked,
                ProductApprovalLog::ACTION_REJECTED,
                (int) $admin->id,
                $reason,
                ProductApprovalLog::SUBMISSION_TYPE_CREATE,
            );

            return $locked->refresh()->load($this->defaultRelations());
        });
    }

    /**
     * Mark a product approved when an authorized admin explicitly activates/publishes
     * through product management (not a bulk rewrite of historical null grandfather rows).
     *
     * Callers must gate this behind products.approve. Writes an approval log when the
     * approval_status actually transitions to APPROVED.
     */
    public function markApprovedByAdminLifecycle(Product $product, ?int $adminId): void
    {
        if ($product->approval_status === ProductApprovalStatus::APPROVED) {
            return;
        }

        $product->fill([
            'approval_status' => ProductApprovalStatus::APPROVED,
            'approved_at' => $product->approved_at ?? now(),
            'approved_by' => $adminId ?? $product->approved_by,
            'rejected_at' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'pending_changes' => null,
        ]);

        $this->writeLog(
            $product,
            ProductApprovalLog::ACTION_APPROVED,
            $adminId,
            'Approved via admin activate/publish.',
            ProductApprovalLog::SUBMISSION_TYPE_CREATE,
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{
     *     attributes: array<string, mixed>,
     *     descriptions: array<int, array<string, mixed>>,
     *     variants: array<int, array<string, mixed>>,
     *     images: array<int, array<string, mixed>>,
     *     guideline: array<string, mixed>|null
     * }
     */
    protected function normalizeEditPayload(array $changes): array
    {
        $attributes = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];

        // Edit reviews must never flip lifecycle through the pending payload.
        unset($attributes['status'], $attributes['approval_status'], $attributes['pending_changes']);

        return [
            'attributes' => $attributes,
            'descriptions' => is_array($changes['descriptions'] ?? null) ? array_values($changes['descriptions']) : [],
            'variants' => is_array($changes['variants'] ?? null) ? array_values($changes['variants']) : [],
            'images' => is_array($changes['images'] ?? null) ? array_values($changes['images']) : [],
            'guideline' => is_array($changes['guideline'] ?? null) ? $changes['guideline'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function applyPendingEditChanges(Product $product, array $changes, int $adminId): void
    {
        $payload = $this->normalizeEditPayload($changes);
        $attributes = $payload['attributes'];
        $attributes['updated_by'] = $adminId;

        // Keep product ACTIVE after applying an approved edit.
        unset($attributes['status']);

        app(ProductService::class)->updateProduct(
            $product,
            $attributes,
            $payload['descriptions'],
            $payload['variants'],
            $payload['images'],
            $payload['guideline'],
            lockAdminFinancialFields: true,
            markApproved: false,
        );
    }

    protected function writeLog(
        Product $product,
        string $action,
        ?int $actedBy,
        ?string $remarks,
        ?string $submissionType = null,
        ?array $changePayload = null,
    ): void {
        ProductApprovalLog::query()->create([
            'product_id' => $product->id,
            'acted_by' => $actedBy,
            'action' => $action,
            'submission_type' => $submissionType,
            'remarks' => $remarks,
            'change_payload' => $changePayload,
        ]);
    }

    protected function assertActorHasPermission(User $actor, string $permission): void
    {
        if (! method_exists($actor, 'hasPermission') || ! $actor->hasPermission($permission)) {
            throw ValidationException::withMessages([
                'product' => ['You are not authorized to perform this approval action.'],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    protected function defaultRelations(): array
    {
        return [
            'supplier.company',
            'category',
            'market',
            'descriptions',
            'variants',
            'images.file',
            'guidelineFile',
            'approvalLogs.actor.profile',
        ];
    }
}
