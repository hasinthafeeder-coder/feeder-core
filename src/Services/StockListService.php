<?php

namespace Feeder\Core\Services;

use Feeder\Core\Models\Market;
use Feeder\Core\Models\Product;
use Feeder\Core\Models\ProductCategory;
use Feeder\Core\Models\ProductVariant;
use Feeder\Core\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class StockListService
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly StockService $stockService,
    ) {}

    public function paginateForAdmin(Request $request): LengthAwarePaginator
    {
        return $this->applyAdminFilters($this->baseVariantQuery(), $request)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProductVariant $variant) => $this->attachRemainingStock($variant));
    }

    public function paginateForSupplier(int $supplierId, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->baseVariantQuery()
            ->whereHas('product', fn (Builder $productQuery) => $productQuery->forSupplier($supplierId));

        if ($search !== null && $search !== '') {
            $this->applySearchFilter($query, $search);
        }

        return $query
            ->orderBy('products.name')
            ->orderBy('product_variants.sort_order')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProductVariant $variant) => $this->attachRemainingStock($variant));
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function listForSupplier(int $supplierId, ?string $search = null): Collection
    {
        $query = $this->baseVariantQuery()
            ->whereHas('product', fn (Builder $productQuery) => $productQuery->forSupplier($supplierId));

        if ($search !== null && $search !== '') {
            $this->applySearchFilter($query, $search);
        }

        return $query
            ->orderBy('products.name')
            ->orderBy('product_variants.sort_order')
            ->get()
            ->each(fn (ProductVariant $variant) => $this->attachRemainingStock($variant));
    }

    public function adminCounts(Request $request): array
    {
        $baseQuery = fn () => $this->applyAdminFilters($this->baseVariantQuery(), $request, applyStockStatusFilter: false);

        return [
            'all' => $baseQuery()->count(),
            'in_stock' => $this->stockService->applyStockStatusFilter($baseQuery(), 'in_stock')->count(),
            'out_of_stock' => $this->stockService->applyStockStatusFilter($baseQuery(), 'out_of_stock')->count(),
        ];
    }

    public function supplierCounts(int $supplierId): array
    {
        $baseQuery = ProductVariant::query()
            ->whereHas('product', fn (Builder $productQuery) => $productQuery->forSupplier($supplierId));

        return [
            'all' => (clone $baseQuery)->count(),
            'in_stock' => $this->stockService->applyStockStatusFilter(clone $baseQuery, 'in_stock')->count(),
            'out_of_stock' => $this->stockService->applyStockStatusFilter(clone $baseQuery, 'out_of_stock')->count(),
        ];
    }

    /**
     * @return Collection<int, Market>
     */
    public function marketFilterOptions(): Collection
    {
        return Market::query()
            ->with(['country', 'currency'])
            ->whereIn('id', Product::query()->select('market_id')->whereNotNull('market_id')->distinct())
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function supplierFilterOptions(): Collection
    {
        return User::query()
            ->with('company')
            ->whereIn('id', function ($subquery): void {
                $subquery->select('supplier_id')
                    ->from('products')
                    ->whereNull('deleted_at')
                    ->distinct();
            })
            ->get()
            ->sortBy(fn (User $user) => mb_strtolower($user->company?->name ?? ''))
            ->values();
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    public function categoryFilterOptions(): Collection
    {
        return ProductCategory::query()
            ->where('is_active', true)
            ->whereIn('id', function ($subquery): void {
                $subquery->select('category_id')
                    ->from('products')
                    ->whereNull('deleted_at')
                    ->whereNotNull('category_id')
                    ->distinct();
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    private function baseVariantQuery(): Builder
    {
        $subquery = $this->stockService->remainingStockSubquery();

        return ProductVariant::query()
            ->select('product_variants.*')
            ->selectSub($subquery, 'remaining_stock')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->with([
                'product.category',
                'product.images.file',
                'product.supplier.company',
                'product.market.country',
                'product.market.currency',
            ]);
    }

    private function applyAdminFilters(
        Builder $query,
        Request $request,
        bool $applyStockStatusFilter = true,
    ): Builder {
        $search = trim((string) $request->input('search', ''));
        $marketId = $request->input('market_id');
        $supplierId = $request->input('supplier_id');
        $categoryId = $request->input('category_id');
        $stockStatus = (string) $request->input('stock_status', '');

        $query
            ->when($marketId, fn (Builder $builder) => $builder->where('products.market_id', (int) $marketId))
            ->when($supplierId, fn (Builder $builder) => $builder->where('products.supplier_id', (int) $supplierId))
            ->when($categoryId, fn (Builder $builder) => $builder->where('products.category_id', $categoryId))
            ->when($search !== '', fn (Builder $builder) => $this->applySearchFilter($builder, $search))
            ->orderBy('products.name')
            ->orderBy('product_variants.sort_order');

        if ($applyStockStatusFilter) {
            $this->stockService->applyStockStatusFilter($query, $stockStatus);
        }

        return $query;
    }

    private function applySearchFilter(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $inner) use ($search): void {
            $inner->where('products.name', 'like', "%{$search}%")
                ->orWhere('product_variants.name', 'like', "%{$search}%")
                ->orWhere('product_variants.barcode', 'like', "%{$search}%")
                ->orWhereHas('product.supplier', function (Builder $supplier) use ($search): void {
                    $supplier->whereHas('company', function (Builder $company) use ($search): void {
                        $company->where('name', 'like', "%{$search}%");
                    });
                });
        });
    }

    private function attachRemainingStock(ProductVariant $variant): ProductVariant
    {
        $variant->setAttribute('remaining_stock', max(0, (int) ($variant->remaining_stock ?? 0)));

        return $variant;
    }
}
