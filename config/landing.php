<?php

/*
| Public landing page at "/" (signed-in users go straight to the dashboard).
| Set the app links when the demo builds are published; until then the page
| shows the download buttons as "coming soon".
*/
return [
    'brand' => env('LANDING_BRAND', 'Fashion House Platform'),

    'app' => [
        'android_url' => env('LANDING_ANDROID_URL'),   // Play Store link or a direct .apk download
        'ios_url'     => env('LANDING_IOS_URL'),       // App Store / TestFlight link
        'is_demo'     => (bool) env('LANDING_APP_IS_DEMO', true),
    ],

    'contact_email' => env('LANDING_CONTACT_EMAIL'),
    'contact_phone' => env('LANDING_CONTACT_PHONE'),
];
