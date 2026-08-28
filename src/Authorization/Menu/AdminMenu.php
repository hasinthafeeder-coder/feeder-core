<?php

namespace Feeder\Core\Authorization\Menu;

class AdminMenu
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
            MenuSection::make('ORDERS')
                ->addItem(
                    MenuItem::make('Order Management')
                        ->icon('shopping_cart')
                        ->permission('orders.view')
                        ->children([
                            MenuItem::make('All Orders')
                                ->route('orders.index')
                                ->permission('orders.view'),

                            MenuItem::make('Customer CRIB')
                                ->route('crib.index')
                                ->permission('customers.crib.view'),
                        ])
                )
                ->addItem(
                    MenuItem::make('Status Update')
                        ->icon('published_with_changes')
                        ->permission('orders.status.update')
                        ->children([
                            MenuItem::make('Bulk Delivery Update')
                                ->route('orders.bulk-delivery-update')
                                ->permission('orders.bulk-delivery-update'),

                            MenuItem::make('Bulk Remind Orders Update')
                                ->route('orders.bulk-remind-update')
                                ->permission('orders.bulk-remind-update'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('PAYMENTS')
                ->addItem(
                    MenuItem::make('Generate Payments')
                        ->icon('payments')
                        ->permission('payments.generate')
                        ->children([
                            MenuItem::make('Courier Payment Generate')
                                ->route('payments.courier.generate')
                                ->permission('payments.courier.generate'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('PAYOUTS')
                ->addItem(
                    MenuItem::make('Generate Payouts')
                        ->icon('account_balance_wallet')
                        ->permission('payouts.generate')
                        ->children([
                            MenuItem::make('Reseller Payouts')
                                ->route('payouts.resellers')
                                ->permission('payouts.resellers'),

                            MenuItem::make('Supplier Payouts')
                                ->route('payouts.suppliers')
                                ->permission('payouts.suppliers'),

                            MenuItem::make('Company Bonus Payouts')
                                ->route('payouts.company-bonus')
                                ->permission('payouts.company-bonus'),
                        ])
                )
                ->addItem(
                    MenuItem::make('Payout Invoice')
                        ->icon('receipt_long')
                        ->permission('payouts.invoice.view')
                        ->children([
                            MenuItem::make('Reseller Invoice')
                                ->route('payouts.invoices.resellers')
                                ->permission('payouts.invoice.resellers'),

                            MenuItem::make('Supplier Invoice')
                                ->route('payouts.invoices.suppliers')
                                ->permission('payouts.invoice.suppliers'),

                            MenuItem::make('Company Bonus Invoice')
                                ->route('payouts.invoices.company-bonus')
                                ->permission('payouts.invoice.company-bonus'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('PRODUCTS')
                ->addItem(
                    MenuItem::make('Product Management')
                        ->icon('inventory_2')
                        ->permission('products.view')
                        ->children([
                            MenuItem::make('Manage Products')
                                ->route('products.index')
                                ->permission('products.view'),

                            MenuItem::make('Product Categories')
                                ->route('product-categories.index')
                                ->permission('product_categories.view'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('CREATE')
                ->addItem(
                    MenuItem::make('Create Options')
                        ->icon('add_circle')
                        ->permission('create.options')
                        ->children([
                            MenuItem::make('Create Delivery Services')
                                ->route('delivery-services.create')
                                ->permission('delivery-services.create'),

                            MenuItem::make('Create Note')
                                ->route('notes.create')
                                ->permission('notes.create'),

                            MenuItem::make('Create Ban Customer')
                                ->route('customers.ban.create')
                                ->permission('customers.ban.create'),
                        ])
                )
                ->addItem(
                    MenuItem::make('Create Profiles')
                        ->icon('person_add')
                        ->permission('profiles.create')
                        ->children([
                            MenuItem::make('Create Company Profile')
                                ->route('companies.create')
                                ->permission('companies.create'),

                            MenuItem::make('Create Reseller Profile')
                                ->route('resellers.create')
                                ->permission('resellers.create'),

                            MenuItem::make('Create Supplier Profile')
                                ->route('suppliers.create')
                                ->permission('suppliers.create'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('ACCOUNTS')
                ->addItem(
                    MenuItem::make('Manage Profiles')
                        ->icon('manage_accounts')
                        ->permission('profiles.manage')
                        ->children([
                            MenuItem::make('Reseller Profile Manage')
                                ->route('resellers.index')
                                ->permission('resellers.view'),

                            MenuItem::make('Supplier Profile Manage')
                                ->route('suppliers.index')
                                ->permission('suppliers.view'),

                            MenuItem::make('Feeder Profile Manage')
                                ->route('feeder-profiles.index')
                                ->permission('feeder-profiles.view'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('OVERVIEW')
                ->addItem(
                    MenuItem::make('Orders Overview')
                        ->icon('insights')
                        ->permission('orders.overview')
                        ->children([
                            MenuItem::make('Supplier Orders Overview')
                                ->route('orders.overview.suppliers')
                                ->permission('orders.overview.suppliers'),

                            MenuItem::make('Reseller Orders Overview')
                                ->route('orders.overview.resellers')
                                ->permission('orders.overview.resellers'),
                        ])
                )
                ->addItem(
                    MenuItem::make('Team Structure Overview')
                        ->icon('groups')
                        ->route('team.structure')
                        ->permission('team.structure.view')
                )
        );

        $menu->addSection(
            MenuSection::make('REPORTS')
                ->addItem(
                    MenuItem::make('Reports & Analytics')
                        ->icon('bar_chart')
                        ->permission('reports.view')
                        ->children([
                            MenuItem::make('Delivery Services Report')
                                ->route('reports.delivery-services')
                                ->permission('reports.delivery-services'),

                            MenuItem::make('Supplier Reports')
                                ->route('reports.suppliers')
                                ->permission('reports.suppliers'),

                            MenuItem::make('Reseller Reports')
                                ->route('reports.resellers')
                                ->permission('reports.resellers'),

                            MenuItem::make('Feeder Earning Report')
                                ->route('reports.feeder-earnings')
                                ->permission('reports.feeder-earnings'),
                        ])
                )
        );

        $menu->addSection(
            MenuSection::make('SETTINGS')
                ->addItem(
                    MenuItem::make('Financial Settings')
                        ->icon('account_balance')
                        ->route('settings.financial')
                        ->permission('settings.view')
                )
        );

        $menu->addSection(
            MenuSection::make('TAX & VAT')
                ->addItem(
                    MenuItem::make('TAX')
                        ->icon('gavel')
                        ->route('tax.index')
                        ->permission('tax.view')
                )
                ->addItem(
                    MenuItem::make('VAT')
                        ->icon('request_quote')
                        ->route('vat.index')
                        ->permission('vat.view')
                )
        );

        return $menu;
    }
}
