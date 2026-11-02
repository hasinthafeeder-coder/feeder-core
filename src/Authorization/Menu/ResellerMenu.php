<?php

namespace Feeder\Core\Authorization\Menu;

class ResellerMenu
{
    public static function build(): Menu
    {
        $menu = new Menu();

        /*
        |--------------------------------------------------------------------------
        | MAIN
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('MAIN')
                ->addItem(
                    MenuItem::make('Dashboard')
                        ->icon('dashboard')
                        ->route('dashboard')
                        ->permission('dashboard.view')
                )
        );

        /*
        |--------------------------------------------------------------------------
        | ORDERS
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('ORDERS')
                // Title "Orders" is replaced in the reseller layout by ui-sidebar-orders:
                // New Orders (Create / Import), Call Center, Ongoing, Completed, Archived.
                ->addItem(
                    MenuItem::make('Orders')
                        ->icon('shopping_cart')
                        ->permission('orders.view')
                        ->children([
                            MenuItem::make('New Orders')
                                ->permission('orders.view')
                                ->children([
                                    MenuItem::make('Create Order')
                                        ->route('orders.create')
                                        ->permission('orders.create'),

                                    MenuItem::make('Import Order')
                                        ->route('orders.index')
                                        ->permission('orders.create'),
                                ]),

                            MenuItem::make('Call Center Orders')
                                ->route('orders.index')
                                ->permission('orders.view'),

                            MenuItem::make('Ongoing Orders')
                                ->route('orders.index')
                                ->permission('orders.view'),

                            MenuItem::make('Completed Orders')
                                ->route('orders.index')
                                ->permission('orders.view'),

                            MenuItem::make('Archived Orders')
                                ->route('orders.index')
                                ->permission('orders.view'),
                        ])
                )
        );

        /*
        |--------------------------------------------------------------------------
        | PRODUCTS
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('PRODUCTS')
                ->addItem(
                    MenuItem::make('Products')
                        ->icon('inventory_2')
                        ->route('products.index')
                        ->permission('products.view')
                )
        );

        /*
        |--------------------------------------------------------------------------
        | TEAM
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('TEAM')
                ->addItem(
                    MenuItem::make('Team')
                        ->icon('groups')
                        ->permission('team.structure.view')
                        ->children([
                            MenuItem::make('Team Tree')
                                ->route('team.structure')
                                ->permission('team.structure.view'),
                        ])
                )
        );

        /*
        |--------------------------------------------------------------------------
        | CALL CENTER
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('CALL CENTER')
                ->addItem(
                    MenuItem::make('Call Center')
                        ->icon('headset_mic')
                        ->permission('call_center.agents.view')
                        ->children([
                            MenuItem::make('Agents')
                                ->route('ui.call-center.agents.index')
                                ->permission('call_center.agents.view'),
                        ])
                )
        );

        /*
        |--------------------------------------------------------------------------
        | ACCOUNT
        | Customers / Reports menu entries remain omitted until implemented.
        | Financial Overview and Settlement Invoices show this reseller's own records.
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('ACCOUNT')
                ->addItem(
                    MenuItem::make('Financial Overview')
                        ->icon('monitoring')
                        ->route('finance.overview')
                        ->permission('dashboard.view')
                )
                ->addItem(
                    MenuItem::make('Settlement Invoices')
                        ->icon('receipt_long')
                        ->route('settlements.invoices.index')
                        ->permission('dashboard.view')
                )
                ->addItem(
                    MenuItem::make('My Profile')
                        ->icon('account_circle')
                        ->route('profile.edit')
                )
        );

        return $menu;
    }
}
