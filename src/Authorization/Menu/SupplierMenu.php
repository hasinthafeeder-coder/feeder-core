<?php

namespace Feeder\Core\Authorization\Menu;

use Feeder\Core\Services\Notification\NotificationInboxService;
use Illuminate\Validation\ValidationException;

class SupplierMenu
{
    public static function build(): Menu
    {
        $menu = new Menu();

        $menu->addSection(
            MenuSection::make('MAIN')
                ->addItem(
                    MenuItem::make('Dashboard')
                        ->icon('dashboard')
                        ->route('dashboard')
                        ->permission('dashboard.view')
                )
                ->addItem(
                    MenuItem::make('Financial Overview')
                        ->icon('monitoring')
                        ->route('finance.overview')
                        ->permission('dashboard.view')
                )
        );

        $menu->addSection(
            MenuSection::make('PRODUCTS')
                ->addItem(
                    MenuItem::make('Products')
                        ->icon('inventory_2')
                        ->permission('products.view')
                        ->children([
                            MenuItem::make('All Products')
                                ->route('products.index')
                                ->permission('products.view'),

                            MenuItem::make('Create Product')
                                ->route('products.create')
                                ->permission('products.create'),
                        ])
                )
                ->addItem(
                    MenuItem::make('Notification Inbox')
                        ->icon('notifications')
                        ->route('notifications.inbox.index')
                        ->permission('notifications.view')
                        ->badge(function ($user) {
                            if ($user === null || (int) $user->company_id <= 0) {
                                return null;
                            }

                            try {
                                $count = app(NotificationInboxService::class)->unreadCountFor($user);
                            } catch (ValidationException) {
                                return null;
                            }

                            return $count > 0 ? $count : null;
                        })
                )
                ->addItem(
                    MenuItem::make('Product Notifications')
                        ->icon('campaign')
                        ->route('notifications.index')
                        ->permission('notifications.view')
                )
        );

        $menu->addSection(
            MenuSection::make('ORDERS')
                ->addItem(
                    MenuItem::make('New Orders')
                        ->icon('add_shopping_cart')
                        ->route('orders.ui.new')
                        ->permission('orders.view')
                )
                ->addItem(
                    MenuItem::make('Print Orders')
                        ->icon('print')
                        ->route('orders.ui.print')
                        ->permission('orders.view')
                )
                ->addItem(
                    MenuItem::make('Packing Orders')
                        ->icon('package_2')
                        ->route('orders.ui.packaging')
                        ->permission('orders.view')
                )
                ->addItem(
                    MenuItem::make('Dispatch Orders')
                        ->icon('local_shipping')
                        ->route('orders.ui.dispatch')
                        ->permission('orders.view')
                )
                ->addItem(
                    MenuItem::make('Completed Orders')
                        ->icon('task_alt')
                        ->route('orders.ui.completed')
                        ->permission('orders.view')
                )
                ->addItem(
                    MenuItem::make('Archived Orders')
                        ->icon('inventory_2')
                        ->route('orders.ui.archived')
                        ->permission('orders.view')
                )
                ->addItem(
                    MenuItem::make('Dispatch Summary')
                        ->icon('summarize')
                        ->route('orders.ui.dispatch-summary')
                        ->permission('orders.view')
                )
        );

        $menu->addSection(
            MenuSection::make('INVENTORY')
                ->addItem(
                    MenuItem::make('GRNs')
                        ->icon('inventory')
                        ->permission('grns.view')
                        ->children([
                            MenuItem::make('All GRNs')
                                ->route('grns.index')
                                ->permission('grns.view'),

                            MenuItem::make('Create GRN')
                                ->route('grns.create')
                                ->permission('grns.create'),
                        ])
                )
                ->addItem(
                    MenuItem::make('Stock')
                        ->icon('warehouse')
                        ->route('stock.index')
                        ->permission('stock.view')
                )
        );

        return $menu;
    }
}
