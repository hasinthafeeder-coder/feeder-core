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
                ->addItem(
                    MenuItem::make('Orders')
                        ->icon('shopping_cart')
                        ->permission('orders.view')
                        ->children([
                            MenuItem::make('All Orders')
                                ->route('orders.index')
                                ->permission('orders.view'),

                            MenuItem::make('Create Order')
                                ->route('orders.create')
                                ->permission('orders.create'),
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
                        ->permission('products.view')
                        ->children([
                            MenuItem::make('All Products')
                                ->route('products.index')
                                ->permission('products.view'),

                        ])
                )
        );

        /*
        |--------------------------------------------------------------------------
        | CUSTOMERS
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('CUSTOMERS')
                ->addItem(
                    MenuItem::make('Customers')
                        ->icon('groups')
                        ->route('customers.index')
                        ->permission('customers.view')
                )
        );

        /*
        |--------------------------------------------------------------------------
        | PAYOUTS
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('PAYOUTS')
                ->addItem(
                    MenuItem::make('Payouts')
                        ->icon('account_balance_wallet')
                        ->permission('payouts.view')
                        ->children([
                            MenuItem::make('Payout History')
                                ->route('payouts.index')
                                ->permission('payouts.view'),
                        ])
                )
        );

        /*
        |--------------------------------------------------------------------------
        | REPORTS
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('REPORTS')
                ->addItem(
                    MenuItem::make('Reports')
                        ->icon('bar_chart')
                        ->route('reports.index')
                        ->permission('reports.view')
                )
        );

        /*
        |--------------------------------------------------------------------------
        | PROFILE
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('ACCOUNT')
                ->addItem(
                    MenuItem::make('My Profile')
                        ->icon('account_circle')
                        ->route('profile.index')
                        ->permission('profile.view')
                )
        );

        return $menu;
    }
}
