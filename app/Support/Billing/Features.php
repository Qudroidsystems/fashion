<?php

namespace App\Support\Billing;

/**
 * Everything a plan can switch on, in plain words for the pricing table.
 * Customers, measurements, styles, quotes, orders, invoices, manual payments
 * and the production board are in every plan and are not listed here.
 */
class Features
{
    public const ALL = [
        'measurement_links' => 'Customers send their own measurements by link',
        'fitting_reminders' => 'Fitting reminders by e-mail',
        'inventory'         => 'Fabric and stock control',
        'pos'               => 'Shop till with cashier shifts',
        'online_payments'   => 'Online payments with your Paystack account',
        'mobile_app'        => 'Staff mobile app',
        'website'           => 'Your own website on a subdomain',
        'projects'          => 'Weddings, events and group orders',
        'purchasing'        => 'Suppliers and purchase orders',
        'custom_workflows'  => 'Your own production stages and quality checklists',
        'website_full'      => 'Website booking and measurement forms, no platform branding',
        'export'            => 'Export your data to spreadsheets',
        'reports_full'      => 'Full reports',
        'custom_domain'     => 'Website on your own domain',
        'api_access'        => 'API access for your own systems',
        'priority_support'  => 'Priority support',
    ];

    public const LIMITS = [
        'customers' => ['Customers', 'customer', 'customers'],
        'staff'     => ['Staff logins', 'staff login', 'staff logins'],
        'branches'  => ['Branches', 'branch', 'branches'],
    ];

    public static function label(string $key): string
    {
        return self::ALL[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
