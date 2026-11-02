<?php

namespace Tests\Unit\Authorization\Menu;

use Feeder\Core\Authorization\Menu\AdminMenu;
use Feeder\Core\Authorization\Menu\Menu;
use Feeder\Core\Authorization\Menu\ResellerMenu;
use Feeder\Core\Authorization\Menu\SupplierMenu;
use PHPUnit\Framework\TestCase;

class Phase2bPortalMenuTest extends TestCase
{
    public function test_supplier_orders_menu_matches_phase_2b_structure(): void
    {
        $titles = $this->sectionItemTitles(SupplierMenu::build(), 'ORDERS');

        $this->assertSame([
            'New Orders',
            'Print Orders',
            'Packing Orders',
            'Dispatch Orders',
            'Completed Orders',
            'Archived Orders',
            'Dispatch Summary',
        ], $titles);

        $this->assertNotContains('Order Payments', $titles);
    }

    public function test_reseller_orders_menu_includes_ongoing_completed_and_archived(): void
    {
        $orders = $this->sectionItems(ResellerMenu::build(), 'ORDERS')[0] ?? null;
        $this->assertNotNull($orders);

        $childTitles = array_map(
            static fn ($item) => $item->getTitle(),
            $orders->getChildren()
        );

        $this->assertContains('Ongoing Orders', $childTitles);
        $this->assertContains('Completed Orders', $childTitles);
        $this->assertContains('Archived Orders', $childTitles);
        $this->assertContains('Call Center Orders', $childTitles);
        $this->assertContains('New Orders', $childTitles);
    }

    public function test_financial_overviews_belong_to_the_supplier_and_reseller_portals(): void
    {
        $supplierOverview = $this->findItem(SupplierMenu::build(), 'Financial Overview');
        $resellerOverview = $this->findItem(ResellerMenu::build(), 'Financial Overview');
        $adminTitles = $this->flattenTitles(AdminMenu::build());

        $this->assertNotNull($supplierOverview);
        $this->assertSame('finance.overview', $supplierOverview->getRoute());
        $this->assertSame('dashboard.view', $supplierOverview->getPermission());
        $this->assertSame('MAIN', $this->sectionTitleForItem(SupplierMenu::build(), 'Financial Overview'));

        $this->assertNotNull($resellerOverview);
        $this->assertSame('finance.overview', $resellerOverview->getRoute());
        $this->assertSame('dashboard.view', $resellerOverview->getPermission());
        $this->assertSame('ACCOUNT', $this->sectionTitleForItem(ResellerMenu::build(), 'Financial Overview'));

        $this->assertNotContains('Supplier Financial Overview', $adminTitles);
        $this->assertNotContains('Reseller Financial Overview', $adminTitles);
        $this->assertContains('Financial Overview', $adminTitles);
    }

    public function test_admin_menu_includes_phase_2b_payment_and_settlement_labels(): void
    {
        $allTitles = $this->flattenTitles(AdminMenu::build());

        $this->assertContains('Courier Payment Update', $allTitles);
        $this->assertContains('Supplier Settlement', $allTitles);
        $this->assertContains('Payment Batches', $allTitles);
        $this->assertContains('Supplier Payments', $allTitles);
        $this->assertContains('Reseller Settlement', $allTitles);
        $this->assertContains('Expenses', $allTitles);

        $this->assertNotContains('Settlement Batches', $allTitles);
        $this->assertNotContains('Supplier Bank Accounts', $allTitles);
        $this->assertNotContains('Bank Exports', $allTitles);
        $this->assertNotContains('Courier Payment Generate', $allTitles);
        $this->assertNotContains('Supplier Payouts', $allTitles);
        $this->assertNotContains('Reseller Payouts', $allTitles);
        $this->assertNotContains('Order Payments', $allTitles);

        $supplierSettlement = null;
        foreach (AdminMenu::build()->getSections() as $section) {
            foreach ($section->getItems() as $item) {
                if ($item->getTitle() === 'Supplier Settlement') {
                    $supplierSettlement = $item;
                    break 2;
                }
            }
        }

        $this->assertNotNull($supplierSettlement);
        $this->assertSame('settlements.suppliers', $supplierSettlement->getRoute());
        $this->assertSame('settlements.suppliers.view', $supplierSettlement->getPermission());
    }

    /**
     * @return list<string>
     */
    private function findItem(Menu $menu, string $title): ?object
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

    private function sectionTitleForItem(Menu $menu, string $title): ?string
    {
        foreach ($menu->getSections() as $section) {
            foreach ($section->getItems() as $item) {
                if ($item->getTitle() === $title) {
                    return $section->getTitle();
                }
            }
        }

        return null;
    }

    private function sectionItemTitles(Menu $menu, string $sectionTitle): array
    {
        return array_map(
            static fn ($item) => $item->getTitle(),
            $this->sectionItems($menu, $sectionTitle)
        );
    }

    /**
     * @return list<object>
     */
    private function sectionItems(Menu $menu, string $sectionTitle): array
    {
        foreach ($menu->getSections() as $section) {
            if ($section->getTitle() === $sectionTitle) {
                return $section->getItems();
            }
        }

        $this->fail("Menu section [{$sectionTitle}] was not found.");
    }

    /**
     * @return list<string>
     */
    private function flattenTitles(Menu $menu): array
    {
        $titles = [];

        foreach ($menu->getSections() as $section) {
            foreach ($section->getItems() as $item) {
                $titles[] = $item->getTitle();
                foreach ($item->getChildren() as $child) {
                    $titles[] = $child->getTitle();
                    foreach ($child->getChildren() as $grandChild) {
                        $titles[] = $grandChild->getTitle();
                    }
                }
            }
        }

        return $titles;
    }
}
