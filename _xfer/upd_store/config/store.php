<?php

/*
| Online store (each fashion house's shop on its website).
| House-specific choices (payment methods, bank details, WhatsApp number, delivery…)
| are made by the house in Online store › Settings and kept in the tenant settings.
| These are platform-wide defaults and limits.
*/
return [
    // Where the template's CSS/JS/fonts live under public/.
    'assets' => env('STORE_ASSETS', 'ecommerce_website'),

    // Unpaid card checkouts (Paystack / Stripe) are cancelled and their stock released after this many hours.
    'card_hold_hours' => (int) env('STORE_CARD_HOLD_HOURS', 2),

    // Bank-transfer, WhatsApp and pay-on-delivery orders hold stock this many days unless the house sets its own.
    'manual_hold_days' => (int) env('STORE_MANUAL_HOLD_DAYS', 3),

    // Products per shop page.
    'per_page' => 24,

    // Currencies Stripe charges without minor units.
    'zero_decimal_currencies' => ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'],

    // Country calling code used to turn local numbers (0803…) into WhatsApp numbers, by house currency.
    'calling_codes' => ['NGN' => '234', 'GHS' => '233', 'KES' => '254', 'ZAR' => '27', 'UGX' => '256', 'TZS' => '255', 'RWF' => '250', 'XOF' => '225', 'GBP' => '44', 'USD' => '1', 'EUR' => '33'],

    // WhatsApp Cloud API (optional, per house) — Graph API version.
    'whatsapp_api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),

    // Single-house installations: serve the house's site and shop at the root of APP_URL instead of the landing page.
    'single_root' => (bool) env('STORE_SINGLE_ROOT', false),
];
