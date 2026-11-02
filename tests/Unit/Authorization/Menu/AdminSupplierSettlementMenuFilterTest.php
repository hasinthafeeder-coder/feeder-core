<?php

namespace Feeder\Core\Tests\Unit\Authorization\Menu;

use Feeder\Core\Authorization\Menu\MenuItem;
use Feeder\Core\Authorization\Services\MenuService;
use Feeder\Core\Authorization\Services\PermissionService;
use Feeder\Core\Enums\UserType;
use Feeder\Core\Models\User;
use Mockery;
use PHPUnit\Framework\TestCase;

class AdminSupplierSettlementMenuFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_manager_with_settlement_view_sees_supplier_settlement_menu(): void
    {
        $menu = $this->filterForPermissions([
            'dashboard.view',
            'settlements.suppliers.view',
        ]);

        $supplierSettlement = $this->findItem($menu, 'Supplier Settlement');

        $this->assertNotNull($supplierSettlement);
        $this->assertSame('settlements.suppliers', $supplierSettlement->getRoute());
        $this->assertSame(
            ['Payment Batches', 'Supplier Payments'],
            array_map(static fn (MenuItem $item): string => $item->getTitle(), $supplierSettlement->getChildren())
        );
        $this->assertNull($this->findItem($menu, 'Reseller Settlement'));
        $this->assertNotNull($this->findItem($menu, 'Dashboard'));
    }

    public function test_super_admin_bypass_sees_supplier_settlement_menu(): void
    {
        $menuService = new MenuService(new PermissionService());
        $user = new User();
        $user->user_type = UserType::SUPER_ADMIN->value;

        $menu = $menuService->getAdminMenu($user);
        $supplierSettlement = $this->findItem($menu, 'Supplier Settlement');

        $this->assertNotNull($supplierSettlement);
        $this->assertSame('settlements.suppliers', $supplierSettlement->getRoute());
        $this->assertNotNull($this->findItem($menu, 'Reseller Settlement'));
        $this->assertNotNull($this->findItem($menu, 'Courier Payment Update'));
        $this->assertNotNull($this->findItem($menu, 'Dashboard'));
    }

    public function test_user_without_settlement_view_does_not_see_supplier_settlement_menu(): void
    {
        $menu = $this->filterForPermissions([
            'dashboard.view',
            'orders.view',
        ]);

        $this->assertNull($this->findItem($menu, 'Supplier Settlement'));
        $this->assertNull($this->findItem($menu, 'Reseller Settlement'));
        $this->assertNotNull($this->findItem($menu, 'Courier Payment Update'));
        $this->assertNotNull($this->findItem($menu, 'Dashboard'));
    }

    public function test_manager_with_reseller_settlement_view_sees_reseller_settlement_menu(): void
    {
        $menu = $this->filterForPermissions([
            'dashboard.view',
            'settlements.resellers.view',
        ]);

        $resellerSettlement = $this->findItem($menu, 'Reseller Settlement');

        $this->assertNotNull($resellerSettlement);
        $this->assertSame('settlements.resellers.batches', $resellerSettlement->getRoute());
        $this->assertSame('settlements.resellers.view', $resellerSettlement->getPermission());
        $this->assertSame(
            ['Payment Batches', 'Plus Report'],
            array_map(static fn (MenuItem $item): string => $item->getTitle(), $resellerSettlement->getChildren())
        );
        $this->assertNull($this->findItem($menu, 'Minus Report'));
        $this->assertNull($this->findItem($menu, 'Supplier Settlement'));
    }

    public function test_manager_with_reseller_payments_view_sees_minus_report_menu(): void
    {
        $menu = $this->filterForPermissions([
            'dashboard.view',
            'settlements.resellers.view',
            'settlements.resellers.payments.view',
        ]);

        $resellerSettlement = $this->findItem($menu, 'Reseller Settlement');

        $this->assertNotNull($resellerSettlement);
        $this->assertSame(
            ['Payment Batches', 'Plus Report', 'Minus Report'],
            array_map(static fn (MenuItem $item): string => $item->getTitle(), $resellerSettlement->getChildren())
        );
        $childRoutes = array_map(
            static fn (MenuItem $item): ?string => $item->getRoute(),
            $resellerSettlement->getChildren()
        );
        $this->assertSame([
            'settlements.resellers.batches',
            'settlements.resellers.plus-report',
            'settlements.resellers.minus-report',
        ], $childRoutes);
    }

    public function test_supplier_settlement_child_routes_remain_registered_on_filtered_menu(): void
    {
        $menu = $this->filterForPermissions(['settlements.suppliers.view']);
        $supplierSettlement = $this->findItem($menu, 'Supplier Settlement');

        $this->assertNotNull($supplierSettlement);

        $childRoutes = array_map(
            static fn (MenuItem $item): ?string => $item->getRoute(),
            $supplierSettlement->getChildren()
        );

        $this->assertSame([
            'settlements.suppliers.batches',
            'settlements.suppliers.payments',
        ], $childRoutes);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function filterForPermissions(array $allowed): \Feeder\Core\Authorization\Menu\Menu
    {
        $permissionService = Mockery::mock(PermissionService::class);
        $permissionService->shouldReceive('hasPermission')
            ->andReturnUsing(fn (User $user, string $permission): bool => in_array($permission, $allowed, true));

        $menuService = new MenuService($permissionService);
        $user = new User();
        $user->user_type = UserType::EMPLOYEE->value;

        return $menuService->getAdminMenu($user);
    }

    private function findItem(\Feeder\Core\Authorization\Menu\Menu $menu, string $title): ?MenuItem
    {
        foreach ($menu->getSections() as $section) {
            foreach ($section->getItems() as $item) {
                if ($item->getTitle() === $title) {
                    return $item;
                }
            }
        }

        return null;
    }
}
