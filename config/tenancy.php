<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy mode
    |--------------------------------------------------------------------------
    |
    | multi  — SaaS: many fashion houses share this installation. A user works
    |          inside the tenant(s) they have an active membership in, and can
    |          switch between them. Platform operators manage tenants.
    |
    | single — one fashion house per installation. Every request runs inside
    |          the default tenant below; tenant switching and the platform
    |          screens are hidden. The data model is identical, so a single
    |          installation can later be turned into SaaS without migrations.
    |
    */

    'mode' => env('TENANCY_MODE', 'multi'),

    // Slug of the tenant used in single mode (created by the seeder).
    'default_tenant' => env('TENANCY_DEFAULT_TENANT', 'default'),

    // Header the mobile apps send to pick a tenant (slug or public id).
    // It is only honoured when the signed-in user is an active member.
    'header' => 'X-Tenant',

    // Allow anyone to create a new fashion house from the register page
    // (multi mode only). When false, only platform operators create tenants.
    'self_signup' => env('TENANCY_SELF_SIGNUP', true),

    // Subscription states (SRS FR-TEN-005) that may still use the app.
    'usable_statuses' => ['trial', 'active', 'past_due'],

    // Days a new self-signup tenant stays on trial.
    'trial_days' => (int) env('TENANCY_TRIAL_DAYS', 14),

    // Defaults for a new tenant.
    'defaults' => [
        'timezone' => env('TENANT_DEFAULT_TIMEZONE', 'Africa/Lagos'),
        'locale'   => 'en',
        'currency' => env('TENANT_DEFAULT_CURRENCY', 'NGN'),
    ],

];
