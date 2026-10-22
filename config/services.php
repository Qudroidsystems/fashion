<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'phone_number' => env('TWILIO_PHONE_NUMBER'),
    ],

    'paystack' => [
        'active'          => env('PAYSTACK_ACTIVE', true),
        'mode'            => env('PAYSTACK_MODE', 'sandbox'),
        'secret_key'      => env('PAYSTACK_SECRET_KEY'),
        'public_key'      => env('PAYSTACK_PUBLIC_KEY'),
        'test_secret_key' => env('PAYSTACK_TEST_SECRET_KEY'),
        'test_public_key' => env('PAYSTACK_TEST_PUBLIC_KEY'),
        'live_secret_key' => env('PAYSTACK_LIVE_SECRET_KEY'),
        'live_public_key' => env('PAYSTACK_LIVE_PUBLIC_KEY'),
    ],

    // Push notifications to the staff and customer mobile apps (Firebase Cloud Messaging).
    'fcm' => [
        'enabled'     => env('FCM_ENABLED', false),
        'credentials' => env('FCM_CREDENTIALS', storage_path('app/fcm-service-account.json')),
        'project_id'  => env('FCM_PROJECT_ID'),
    ],


    // Sign in with Google (OAuth 2.0 / OpenID Connect). Create the client at
    // https://console.cloud.google.com/apis/credentials and add the redirect URI below.
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),   // defaults to {APP_URL}/auth/google/callback
        // Shoppers on the house shops all come back through one address on the platform
        // (house sites live on many subdomains/domains, Google needs fixed redirect URIs).
        'shop_redirect' => env('GOOGLE_SHOP_REDIRECT_URI'),   // defaults to {APP_URL}/shop-auth/google/callback
        // Staff app (Google sign-in on the phone): every OAuth client id whose ID tokens the API accepts —
        // the Web client id above is always accepted; add the iOS and Android client ids here, comma-separated.
        'mobile_client_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_MOBILE_CLIENT_IDS', ''))))),
    ],

];
