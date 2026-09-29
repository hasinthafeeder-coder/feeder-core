<?php

namespace Feeder\Core\Authorization\Menu;

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
