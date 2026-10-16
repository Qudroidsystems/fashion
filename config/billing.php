<?php

/*
| Subscriptions for fashion houses (multi-house mode only; single mode has no plans or limits).
| Plans, prices, limits and features live in the `plans` table and are edited by the platform
| super admin at /platform/plans. The values here are the platform's own settings.
*/
return [
    'enabled' => env('BILLING_ENABLED', true),

    // New houses start with a trial of this plan, then fall back to the free plan unless they pay.
    'trial_plan' => env('BILLING_TRIAL_PLAN', 'pro'),

    // Days a house keeps its paid plan after a renewal payment fails, before it moves to the free plan.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Days before the trial or a renewal to warn the owner.
    'notice_days' => (int) env('BILLING_NOTICE_DAYS', 3),

    // Your own Paystack account (not a fashion house's). Recurring renewals charge the saved card.
    'paystack' => [
        'public_key' => env('PLATFORM_PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PLATFORM_PAYSTACK_SECRET_KEY'),
        'currency'   => env('BILLING_CURRENCY', 'NGN'),
    ],

    /*
    | Which plan feature each screen and API endpoint needs, by route name.
    | Without the feature, pages can still be opened (read-only, with an upgrade banner) but
    | nothing can be saved — so a house that downgrades never loses sight of its own records.
    | Features listed in `block_reads` are blocked entirely (they are the action itself).
    */
    'route_features' => [
        'inventory'         => ['inventory.*', 'stock-transfers.*', 'stock-adjustments.*', 'material-issues.*',
                                'api.v1.inventory-items.*', 'api.v1.stock-transfers.*', 'api.v1.stock-counts.*', 'api.v1.material-issues.*'],
        'purchasing'        => ['suppliers.*', 'purchase-orders.*', 'goods-receipts.*',
                                'api.v1.suppliers.*', 'api.v1.purchase-orders.*', 'api.v1.goods-receipts.*'],
        'pos'               => ['pos.*', 'api.v1.pos.*'],
        'projects'          => ['projects.index', 'projects.create', 'projects.store', 'projects.show', 'projects.edit', 'projects.update',
                                'projects.confirm', 'projects.cancel', 'projects.complete', 'projects.tracking', 'projects.participants.*',
                                'projects.garments.*', 'projects.milestones.*', 'projects.updates.*', 'projects.budget.*', 'projects.expenses.*',
                                'projects.schedule.*', 'api.v1.projects.*'],
        'custom_workflows'  => ['production.setup', 'production.workflows.*', 'production.checklists.*'],
        'measurement_links' => ['measurement-requests.*', 'api.v1.measurement-requests.*'],
        'online_payments'   => ['orders.payment-link', 'invoices.payment-link', 'payment-intents.*'],
        'export'            => ['customers.export'],
    ],
    'block_reads' => ['export'],
];
