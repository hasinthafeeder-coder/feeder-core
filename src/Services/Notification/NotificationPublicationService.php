<?php

namespace Feeder\Core\Services\Notification;

use DateTimeInterface;
use Feeder\Core\Enums\ApplicationType;
use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\FileCategory;
use Feeder\Core\Enums\NotificationAudienceType;
use Feeder\Core\Enums\NotificationDeliveryChannel;
use Feeder\Core\Enums\NotificationDeliveryStatus;
use Feeder\Core\Enums\NotificationType;
use Feeder\Core\Events\NotificationCreated;
use Feeder\Core\Models\File;
use Feeder\Core\Models\Notification;
use Feeder\Core\Models\NotificationDelivery;
use Feeder\Core\Models\NotificationRecipient;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\User;
use Feeder\Core\Services\FileService;
use Feeder\Core\Services\ProductEligibilityService;
use Feeder\Core\Services\UuidService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates channel-independent notification records and initial IN_APP deliveries.
 *
 * Email/SMS channels are intentionally not created here: host mailer is log-only
 * and no production notification mail jobs are wired yet.
 */
class NotificationPublicationService
{
    public const PERMISSION_VIEW = 'notifications.view';

    public const PERMISSION_CREATE = 'notifications.create';

    public function __construct(
        private readonly SupplierNotificationRecipientResolver $supplierRecipients,
        private readonly ResellerProductNotificationRecipientResolver $resellerRecipients,
        private readonly ResellerUserNotificationRecipientResolver $resellerUserRecipients,
        private readonly SupplierAssignedResellerRecipientResolver $assignedResellerRecipients,
        private readonly ProductEligibilityService $productEligibility,
        private readonly FileService $files,
    ) {}

    public function publish(
        User $actor,
        PublishNotificationData $data,
        ?UploadedFile $banner = null,
    ): Notification {
        if ($data->type->isFinancialAutomated()) {
            throw ValidationException::withMessages([
                'type' => ['Financial notifications must be published through the financial notification workflow.'],
            ]);
        }

        if ($data->type->isMeetingAutomated()) {
            throw ValidationException::withMessages([
                'type' => ['Meeting reminders must be published through the meeting reminder workflow.'],
            ]);
        }

        if ($data->type->isSupplierManaged() || $data->audienceType->isSupplierAudience()) {
            throw ValidationException::withMessages([
                'type' => ['Supplier product notifications must be published through the supplier product workflow.'],
            ]);
        }

        if ($data->audienceType === NotificationAudienceType::SELECTED_RESELLER_USERS) {
            throw ValidationException::withMessages([
                'audience_type' => ['Selected reseller users are reserved for system notification workflows.'],
            ]);
        }

        return $this->publishResolved(
            actor: $actor,
            data: $data,
            targetUsers: $this->resolveAdminAudience($data->audienceType, $data->recipientUserIds),
            product: null,
            publisherCompanyId: null,
            banner: $banner,
            application: ApplicationType::ADMIN,
            defaultSourceEventType: null,
            meetingId: $data->meetingId,
        );
    }

    /**
     * System/finance notifications for exact selected reseller or supplier users.
     *
     * Idempotent when source_event_key is provided. Recipients are re-validated
     * server-side; never trust caller-supplied company membership alone.
     */
    public function publishFinancialEvent(
        User $actor,
        PublishNotificationData $data,
    ): Notification {
        if (! $data->type->isFinancialAutomated()) {
            throw ValidationException::withMessages([
                'type' => ['Only automated financial notification types are allowed.'],
            ]);
        }

        if (! $data->audienceType->isFinancialAudience()) {
            throw ValidationException::withMessages([
                'audience_type' => ['Financial notifications must target selected reseller or supplier users.'],
            ]);
        }

        $targetUsers = match ($data->audienceType) {
            NotificationAudienceType::SELECTED_RESELLER_USERS => $this->resellerUserRecipients
                ->resolveSelectedResellerUsers($data->recipientUserIds),
            NotificationAudienceType::SELECTED_SUPPLIER_USERS => $this->supplierRecipients
                ->resolveSelectedSupplierUsers($data->recipientUserIds),
            default => throw ValidationException::withMessages([
                'audience_type' => ['The selected audience is not allowed for financial notifications.'],
            ]),
        };

        return $this->publishResolved(
            actor: $actor,
            data: $data,
            targetUsers: $targetUsers,
            product: null,
            publisherCompanyId: null,
            banner: null,
            application: ApplicationType::ADMIN,
            defaultSourceEventType: $data->sourceEventType ?? 'finance.notification.published',
        );
    }

    /**
     * System meeting reminders for exact selected reseller users.
     *
     * Idempotent when source_event_key is provided.
     */
    public function publishMeetingEvent(
        User $actor,
        PublishNotificationData $data,
    ): Notification {
        if (! $data->type->isMeetingAutomated()) {
            throw ValidationException::withMessages([
                'type' => ['Only automated meeting notification types are allowed.'],
            ]);
        }

        if ($data->audienceType !== NotificationAudienceType::SELECTED_RESELLER_USERS) {
            throw ValidationException::withMessages([
                'audience_type' => ['Meeting reminders must target selected reseller users.'],
            ]);
        }

        $targetUsers = $this->resellerUserRecipients
            ->resolveSelectedResellerUsers($data->recipientUserIds);

        return $this->publishResolved(
            actor: $actor,
            data: $data,
            targetUsers: $targetUsers,
            product: null,
            publisherCompanyId: null,
            banner: null,
            application: ApplicationType::ADMIN,
            defaultSourceEventType: $data->sourceEventType ?? 'meeting.reminder.published',
            meetingId: $data->meetingId,
        );
    }

    public function publishAnnouncement(
        User $actor,
        PublishNotificationData $data,
        ?UploadedFile $banner = null,
    ): Notification {
        return $this->publish($actor, new PublishNotificationData(
            type: NotificationType::ANNOUNCEMENT,
            title: $data->title,
            body: $data->body,
            audienceType: $data->audienceType,
            recipientUserIds: $data->recipientUserIds,
            publishAt: $data->publishAt,
            expiresAt: $data->expiresAt,
            bannerFileUuid: $data->bannerFileUuid,
            sourceEventType: $data->sourceEventType ?? 'admin.announcement.published',
            sourceEventKey: $data->sourceEventKey,
            meetingId: $data->meetingId,
        ), $banner);
    }

    public function publishSupplierMessage(
        User $actor,
        PublishNotificationData $data,
        ?UploadedFile $banner = null,
    ): Notification {
        if ($data->audienceType !== NotificationAudienceType::SELECTED_SUPPLIER_USERS) {
            throw ValidationException::withMessages([
                'audience_type' => ['Supplier messages must target selected supplier users.'],
            ]);
        }

        return $this->publish($actor, new PublishNotificationData(
            type: NotificationType::SUPPLIER_MESSAGE,
            title: $data->title,
            body: $data->body,
            audienceType: NotificationAudienceType::SELECTED_SUPPLIER_USERS,
            recipientUserIds: $data->recipientUserIds,
            publishAt: $data->publishAt,
            expiresAt: $data->expiresAt,
            bannerFileUuid: $data->bannerFileUuid,
            sourceEventType: $data->sourceEventType ?? 'admin.supplier_message.published',
            sourceEventKey: $data->sourceEventKey,
        ), $banner);
    }

    public function publishForSupplierProduct(
        User $supplier,
        Product $product,
        PublishNotificationData $data,
        ?UploadedFile $banner = null,
    ): Notification {
        $this->assertSupplierActor($supplier);
        $this->assertSupplierOwnsProduct($supplier, $product);

        if (! $data->type->isSupplierManaged()) {
            throw ValidationException::withMessages([
                'type' => ['Suppliers may only publish product offers, restock notices, or product information updates.'],
            ]);
        }

        if (! $data->audienceType->isSupplierAudience()) {
            throw ValidationException::withMessages([
                'audience_type' => ['Suppliers may only target resellers eligible for the selected product.'],
            ]);
        }

        $targetUsers = match ($data->audienceType) {
            NotificationAudienceType::ALL_ELIGIBLE_RESELLERS_FOR_PRODUCT => $this->resellerRecipients
                ->allEligibleResellersForProduct($product),
            NotificationAudienceType::SELECTED_ELIGIBLE_RESELLERS_FOR_PRODUCT => $this->resellerRecipients
                ->resolveSelectedEligibleResellersForProduct($product, $data->recipientUserIds),
            NotificationAudienceType::ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER => $this->assignedResellerRecipients
                ->allAssignedResellerUsersForSupplier($supplier),
            default => throw ValidationException::withMessages([
                'audience_type' => ['The selected audience is not allowed for supplier notifications.'],
            ]),
        };

        return $this->publishResolved(
            actor: $supplier,
            data: $data,
            targetUsers: $targetUsers,
            product: $product,
            publisherCompanyId: (int) $supplier->company_id,
            banner: $banner,
            application: ApplicationType::SUPPLIER,
            defaultSourceEventType: 'supplier.product_notification.published',
        );
    }

    /**
     * Supplier portal notices to admin-assigned resellers (no manual audience selection).
     */
    public function publishForSupplierAssignedResellers(
        User $supplier,
        PublishNotificationData $data,
        ?UploadedFile $banner = null,
        ?Product $product = null,
    ): Notification {
        $this->assertSupplierActor($supplier);

        if ($product !== null) {
            $this->assertSupplierOwnsProduct($supplier, $product);

            if (! $this->productEligibility->isProductSellable($product)) {
                throw ValidationException::withMessages([
                    'product_id' => ['The selected product is not available for reseller notifications.'],
                ]);
            }
        }

        if (! $data->type->isSupplierManaged()) {
            throw ValidationException::withMessages([
                'type' => ['Suppliers may only publish product offers, restock notices, or product information updates.'],
            ]);
        }

        if ($data->audienceType !== NotificationAudienceType::ALL_ASSIGNED_RESELLERS_FOR_SUPPLIER) {
            throw ValidationException::withMessages([
                'audience_type' => ['Supplier notices must target resellers assigned to this supplier.'],
            ]);
        }

        $targetUsers = $this->assignedResellerRecipients
            ->allAssignedResellerUsersForSupplier($supplier);

        return $this->publishResolved(
            actor: $supplier,
            data: $data,
            targetUsers: $targetUsers,
            product: $product,
            publisherCompanyId: (int) $supplier->company_id,
            banner: $banner,
            application: ApplicationType::SUPPLIER,
            defaultSourceEventType: 'supplier.assigned_reseller_notification.published',
        );
    }

    public function updateContent(
        Notification $notification,
        User $actor,
        UpdateNotificationData $data,
        ?UploadedFile $banner = null,
    ): Notification {
        $title = $this->requiredText($data->title, 'title', 200);
        $body = $this->requiredText($data->body, 'body', 10000);
        $publishAt = $this->timestamp($data->publishAt, 'publish_at');
        $expiresAt = $this->timestamp($data->expiresAt, 'expires_at');

        if ($publishAt === null) {
            throw ValidationException::withMessages([
                'publish_at' => ['The publish time is required.'],
            ]);
        }

        $this->assertSchedule($publishAt, $expiresAt);

        $application = $notification->publisher_company_id
            ? ApplicationType::SUPPLIER
            : ApplicationType::ADMIN;

        $bannerFileUuid = $notification->banner_file_uuid;

        if ($banner !== null) {
            $bannerFileUuid = $this->storeBanner($actor, $banner, $application);
        } elseif ($data->removeBanner) {
            $bannerFileUuid = null;
        }

        return DB::transaction(function () use (
            $notification,
            $title,
            $body,
            $publishAt,
            $expiresAt,
            $bannerFileUuid,
        ): Notification {
            $locked = Notification::query()
                ->whereKey($notification->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'notification' => ['The notification could not be found.'],
                ]);
            }

            $locked->forceFill([
                'title' => $title,
                'body' => $body,
                'publish_at' => $publishAt,
                'expires_at' => $expiresAt,
                'banner_file_uuid' => $bannerFileUuid,
            ])->save();

            return $this->loadForDisplay($locked);
        });
    }

    public function loadForDisplay(Notification $notification): Notification
    {
        return $notification->loadMissing([
            'creator',
            'product',
            'publisherCompany',
            'bannerFile',
            'meeting',
            'recipients.recipientUser.company',
            'recipients.deliveries',
        ]);
    }

    public function assertSupplierCanManage(Notification $notification, User $actor): void
    {
        $this->assertSupplierActor($actor);

        if ((int) $notification->publisher_company_id !== (int) $actor->company_id
            || (int) $notification->created_by !== (int) $actor->id
            || ! $notification->type->isSupplierManaged()
        ) {
            throw ValidationException::withMessages([
                'notification' => ['You are not allowed to manage this notification.'],
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $targetUsers
     */
    private function publishResolved(
        User $actor,
        PublishNotificationData $data,
        Collection $targetUsers,
        ?Product $product,
        ?int $publisherCompanyId,
        ?UploadedFile $banner,
        ApplicationType $application,
        ?string $defaultSourceEventType,
        ?int $meetingId = null,
    ): Notification {
        $sourceEventKey = $this->normalizeOptionalKey($data->sourceEventKey, 'source_event_key');
        $resolvedMeetingId = $meetingId ?? $data->meetingId;

        if ($sourceEventKey !== null) {
            $existing = Notification::query()
                ->where('source_event_key', $sourceEventKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $title = $this->requiredText($data->title, 'title', 200);
        $body = $this->requiredText($data->body, 'body', 10000);
        $publishAt = $this->timestamp($data->publishAt, 'publish_at') ?? Carbon::now();
        $expiresAt = $this->timestamp($data->expiresAt, 'expires_at');
        $this->assertSchedule($publishAt, $expiresAt);

        $bannerFileUuid = $banner !== null
            ? $this->storeBanner($actor, $banner, $application)
            : $this->resolveBannerFileUuid($data->bannerFileUuid);
        $sourceEventType = $this->normalizeOptionalKey(
            $data->sourceEventType ?? $defaultSourceEventType,
            'source_event_type',
            80
        );
        $createdNew = false;

        try {
            $notification = DB::transaction(function () use (
                $actor,
                $data,
                $title,
                $body,
                $publishAt,
                $expiresAt,
                $bannerFileUuid,
                $targetUsers,
                $product,
                $publisherCompanyId,
                $sourceEventType,
                $sourceEventKey,
                $resolvedMeetingId,
                &$createdNew,
            ): Notification {
                if ($sourceEventKey !== null) {
                    $existing = Notification::query()
                        ->where('source_event_key', $sourceEventKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $notification = Notification::query()->create([
                    'type' => $data->type,
                    'title' => $title,
                    'body' => $body,
                    'banner_file_uuid' => $bannerFileUuid,
                    'audience_type' => $data->audienceType,
                    'publish_at' => $publishAt,
                    'expires_at' => $expiresAt,
                    'created_by' => (int) $actor->id,
                    'product_id' => $product?->id,
                    'publisher_company_id' => $publisherCompanyId,
                    'meeting_id' => $resolvedMeetingId,
                    'source_event_type' => $sourceEventType,
                    'source_event_key' => $sourceEventKey,
                ]);

                $this->createRecipientsAndInAppDeliveries($notification, $targetUsers);
                $createdNew = true;

                return $notification->fresh(['recipients.deliveries', 'product', 'meeting']) ?? $notification;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->resolveDuplicateByEventKey($sourceEventKey, $exception);
        } catch (QueryException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if ($driverCode === 1062 && $sourceEventKey !== null) {
                return $this->resolveDuplicateByEventKey($sourceEventKey, $exception);
            }

            throw $exception;
        }

        if ($createdNew) {
            $this->dispatchCreated($notification);
        }

        return $notification;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function createRecipientsAndInAppDeliveries(Notification $notification, Collection $users): void
    {
        if ($users->isEmpty()) {
            throw ValidationException::withMessages([
                'audience_type' => ['No eligible recipients were found for this notification.'],
            ]);
        }

        $now = Carbon::now();

        foreach ($users as $user) {
            $companyId = (int) $user->company_id;

            if ($companyId <= 0) {
                throw ValidationException::withMessages([
                    'recipient_user_ids' => ['Each recipient must belong to a company.'],
                ]);
            }

            $recipient = NotificationRecipient::query()->create([
                'notification_id' => (int) $notification->id,
                'recipient_user_id' => (int) $user->id,
                'recipient_company_id' => $companyId,
                'read_at' => null,
            ]);

            NotificationDelivery::query()->create([
                'notification_recipient_id' => (int) $recipient->id,
                'channel' => NotificationDeliveryChannel::IN_APP,
                'status' => NotificationDeliveryStatus::DELIVERED,
                'delivered_at' => $now,
                'failure_reason' => null,
            ]);
        }
    }

    /**
     * @param  list<int>  $recipientUserIds
     * @return Collection<int, User>
     */
    private function resolveAdminAudience(NotificationAudienceType $audienceType, array $recipientUserIds): Collection
    {
        return match ($audienceType) {
            NotificationAudienceType::ALL_ELIGIBLE_RESELLERS => $this->resellerUserRecipients->allEligibleResellerUsers(),
            NotificationAudienceType::ALL_ELIGIBLE_SUPPLIERS => $this->supplierRecipients->allEligibleSupplierUsers(),
            NotificationAudienceType::ALL_USERS => $this->resellerUserRecipients->allActiveUsersAcrossPortals(),
            NotificationAudienceType::SELECTED_SUPPLIER_USERS => $this->supplierRecipients->resolveSelectedSupplierUsers($recipientUserIds),
            default => throw ValidationException::withMessages([
                'audience_type' => ['The selected audience is not allowed for admin notifications.'],
            ]),
        };
    }

    private function assertSupplierActor(User $actor): void
    {
        $actor->loadMissing('company.portal');

        if (! $actor->isSupplier()) {
            throw ValidationException::withMessages([
                'actor' => ['Only supplier users can manage supplier notifications.'],
            ]);
        }

        $company = $actor->company;

        if ($company === null || $company->trashed()) {
            throw ValidationException::withMessages([
                'actor' => ['The supplier company could not be found.'],
            ]);
        }

        $status = $company->status instanceof CompanyStatus
            ? $company->status
            : CompanyStatus::tryFrom((string) $company->status);

        if ($status !== CompanyStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'actor' => ['The supplier company is not active.'],
            ]);
        }
    }

    private function assertSupplierOwnsProduct(User $supplier, Product $product): void
    {
        if ((int) $product->supplier_id !== (int) $supplier->id) {
            throw ValidationException::withMessages([
                'product_id' => ['You can only notify resellers about your own products.'],
            ]);
        }
    }

    private function storeBanner(User $actor, UploadedFile $banner, ApplicationType $application): string
    {
        $uploadedBy = is_string($actor->uuid) && strlen($actor->uuid) === 10
            ? $actor->uuid
            : null;

        try {
            $response = $this->files->upload(
                $banner,
                $application->value,
                'NOTIFICATION',
                UuidService::generate(),
                FileCategory::NOTIFICATION_BANNER->value,
                $uploadedBy,
                ['purpose' => 'notification_banner'],
            );
        } catch (RequestException|ConnectionException|Throwable) {
            throw ValidationException::withMessages([
                'banner' => ['Unable to store the notification banner. Try again.'],
            ]);
        }

        $uuid = strtoupper(trim((string) data_get($response, 'file.uuid')));

        if ($uuid === '' || preg_match('/^[A-Z0-9]{10}$/', $uuid) !== 1) {
            throw ValidationException::withMessages([
                'banner' => ['Unable to store the notification banner. Try again.'],
            ]);
        }

        return $this->resolveBannerFileUuid($uuid) ?? $uuid;
    }

    private function resolveBannerFileUuid(?string $bannerFileUuid): ?string
    {
        if ($bannerFileUuid === null || trim($bannerFileUuid) === '') {
            return null;
        }

        $uuid = strtoupper(trim($bannerFileUuid));

        if (strlen($uuid) !== 10) {
            throw ValidationException::withMessages([
                'banner_file_uuid' => ['Banner file UUID must be a 10-character public id.'],
            ]);
        }

        $file = File::query()
            ->where('uuid', $uuid)
            ->whereNull('deleted_at')
            ->first();

        if ($file === null
            || (string) $file->category !== FileCategory::NOTIFICATION_BANNER->value
            || (string) $file->status !== 'ACTIVE'
        ) {
            throw ValidationException::withMessages([
                'banner_file_uuid' => ['Banner must reference an active NOTIFICATION_BANNER file.'],
            ]);
        }

        return $uuid;
    }

    private function assertSchedule(Carbon $publishAt, ?Carbon $expiresAt): void
    {
        if ($expiresAt !== null && $expiresAt->lte($publishAt)) {
            throw ValidationException::withMessages([
                'expires_at' => ['Expiry must be after the publish time.'],
            ]);
        }
    }

    private function requiredText(string $value, string $field, int $max): string
    {
        $value = trim($value);

        if ($value === '') {
            throw ValidationException::withMessages([
                $field => ["The {$field} field is required."],
            ]);
        }

        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([
                $field => ["The {$field} may not be greater than {$max} characters."],
            ]);
        }

        return $value;
    }

    private function normalizeOptionalKey(?string $value, string $field, int $max = 80): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages([
                $field => ["The {$field} may not be greater than {$max} characters."],
            ]);
        }

        return $value;
    }

    private function timestamp(DateTimeInterface|string|null $value, string $field): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof DateTimeInterface
                ? Carbon::instance(\DateTimeImmutable::createFromInterface($value))
                : Carbon::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                $field => ["The {$field} must be a valid date/time."],
            ]);
        }
    }

    private function resolveDuplicateByEventKey(?string $sourceEventKey, \Throwable $exception): Notification
    {
        if ($sourceEventKey === null) {
            throw $exception;
        }

        $existing = Notification::query()
            ->where('source_event_key', $sourceEventKey)
            ->first();

        if ($existing === null) {
            throw $exception;
        }

        return $existing;
    }

    private function dispatchCreated(Notification $notification): void
    {
        if (! function_exists('event')) {
            return;
        }

        event(new NotificationCreated(
            notificationId: (int) $notification->id,
            notificationUuid: (string) $notification->uuid,
            sourceEventKey: $notification->source_event_key,
        ));
    }
}
