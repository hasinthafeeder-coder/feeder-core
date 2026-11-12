<?php

namespace Feeder\Core\Services\Notification;

use Feeder\Core\Enums\CompanyStatus;
use Feeder\Core\Enums\PortalCode;
use Feeder\Core\Enums\UserStatus;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\User;
use Feeder\Core\Services\ProductEligibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reseller recipients eligible to access/buy a supplier product.
 *
 * Eligibility mirrors reseller catalog rules: supplier assignment, market
 * access, active reseller company/user, and sellable product state.
 */
class ResellerProductNotificationRecipientResolver
{
    public function __construct(
        private readonly ProductEligibilityService $productEligibility,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function allEligibleResellersForProduct(Product $product): Collection
    {
        $this->assertProductSellable($product);

        return $this->eligibleQuery($product)->orderBy('users.id')->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    public function resolveSelectedEligibleResellersForProduct(Product $product, array $userIds): Collection
    {
        $this->assertProductSellable($product);

        $normalized = $this->normalizeIds($userIds);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => ['Select at least one eligible reseller recipient.'],
            ]);
        }

        $users = $this->eligibleQuery($product)
            ->whereIn('users.id', $normalized)
            ->orderBy('users.id')
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->id);

        $missing = array_values(array_filter(
            $normalized,
            static fn (int $id): bool => ! $users->has($id)
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'recipient_user_ids' => [
                    'One or more selected recipients are not eligible for this product.',
                ],
            ]);
        }

        return $users->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function eligibleQuery(Product $product)
    {
        $resellerPortalId = DB::table('portals')
            ->where('code', PortalCode::RESELLER->value)
            ->value('id');

        $marketId = (int) $product->market_id;
        $supplierId = (int) $product->supplier_id;

        return User::query()
            ->select('users.*')
            ->join('companies', 'companies.id', '=', 'users.company_id')
            ->join(
                'reseller_supplier_assignments as rsa',
                'rsa.reseller_id',
                '=',
                'users.id'
            )
            ->join(
                'reseller_market_access as rma',
                'rma.company_id',
                '=',
                'companies.id'
            )
            ->whereNull('users.deleted_at')
            ->whereNull('companies.deleted_at')
            ->where('users.status', UserStatus::ACTIVE->value)
            ->where('companies.status', CompanyStatus::ACTIVE->value)
            ->where('companies.portal_id', $resellerPortalId ?: 0)
            ->where('rsa.supplier_id', $supplierId)
            ->where('rma.market_id', $marketId)
            ->distinct();
    }

    private function assertProductSellable(Product $product): void
    {
        if (! $this->productEligibility->isProductSellable($product)) {
            throw ValidationException::withMessages([
                'product_id' => ['The selected product is not available for reseller notifications.'],
            ]);
        }
    }

    /**
     * @param  list<int|string>  $userIds
     * @return list<int>
     */
    private function normalizeIds(array $userIds): array
    {
        $normalized = [];

        foreach ($userIds as $id) {
            $value = (int) $id;

            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }
}
