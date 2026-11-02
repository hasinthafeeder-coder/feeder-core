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
     * Supplier submits (or resubmits) a product for admin review.
     *
     * Keeps lifecycle status as DRAFT so the product cannot become reseller-visible
     * until an admin approves it into ACTIVE + APPROVED.
     */
    public function submitForReview(Product $product, int $submittedBy): Product
    {
        if ($product->approval_status === ProductApprovalStatus::APPROVED
            && $product->status === ProductStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'product' => ['This product is already approved and active.'],
            ]);
        }

        if ($product->approval_status === ProductApprovalStatus::PENDING_REVIEW) {
            // Allow content refresh / explicit resubmit while already pending.
            return DB::transaction(function () use ($product, $submittedBy): Product {
                $product->fill([
                    'status' => ProductStatus::DRAFT,
                    'approval_status' => ProductApprovalStatus::PENDING_REVIEW,
                    'submitted_for_review_at' => now(),
                    'updated_by' => $submittedBy,
                    'rejection_reason' => null,
                    'rejected_at' => null,
                    'rejected_by' => null,
                ]);
                $product->save();

                $this->writeLog(
                    $product,
                    ProductApprovalLog::ACTION_SUBMITTED,
                    $submittedBy,
                    'Resubmitted for review.'
                );

                return $product->refresh()->load($this->defaultRelations());
            });
        }

        return DB::transaction(function () use ($product, $submittedBy): Product {
            $product->fill([
                'status' => ProductStatus::DRAFT,
                'approval_status' => ProductApprovalStatus::PENDING_REVIEW,
                'submitted_for_review_at' => now(),
                'updated_by' => $submittedBy,
                'approved_at' => null,
                'approved_by' => null,
                'rejection_reason' => null,
                'rejected_at' => null,
                'rejected_by' => null,
            ]);
            $product->save();

            $this->writeLog(
                $product,
                ProductApprovalLog::ACTION_SUBMITTED,
                $submittedBy,
                'Submitted for admin review.'
            );

            return $product->refresh()->load($this->defaultRelations());
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

            $locked->fill([
                'status' => ProductStatus::ACTIVE,
                'approval_status' => ProductApprovalStatus::APPROVED,
                'published_at' => $locked->published_at ?? now(),
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'rejected_at' => null,
                'rejected_by' => null,
                'rejection_reason' => null,
                'updated_by' => $admin->id,
            ]);
            $locked->save();

            $this->writeLog(
                $locked,
                ProductApprovalLog::ACTION_APPROVED,
                (int) $admin->id,
                null
            );

            return $locked->refresh()->load($this->defaultRelations());
        });
    }

    public function reject(Product $product, User $admin, string $reason): Product
    {
        $this->assertActorHasPermission($admin, self::PERMISSION_REJECT);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => ['A rejection reason is required.'],
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

            $locked->fill([
                'status' => ProductStatus::DRAFT,
                'approval_status' => ProductApprovalStatus::REJECTED,
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
                $reason
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
        ]);

        $this->writeLog(
            $product,
            ProductApprovalLog::ACTION_APPROVED,
            $adminId,
            'Approved via admin activate/publish.'
        );
    }

    protected function writeLog(Product $product, string $action, ?int $actedBy, ?string $remarks): void
    {
        ProductApprovalLog::query()->create([
            'product_id' => $product->id,
            'acted_by' => $actedBy,
            'action' => $action,
            'remarks' => $remarks,
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
