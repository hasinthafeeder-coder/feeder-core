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

        return $menu;
    }
}
