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
        | Customers / Payouts / Reports menu entries are omitted until their
        | reseller portal routes and UI are implemented.
        |--------------------------------------------------------------------------
        */

        $menu->addSection(
            MenuSection::make('ACCOUNT')
                ->addItem(
                    MenuItem::make('My Profile')
                        ->icon('account_circle')
                        ->route('profile.edit')
                )
        );

        return $menu;
    }
}
