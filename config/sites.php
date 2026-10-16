<?php

/*
| Each fashion house's public website.
|   - On a subdomain: {house-slug}.{SITES_DOMAIN}   (needs a wildcard DNS record *.SITES_DOMAIN → this server)
|   - Preview without DNS: {APP_URL}/s/{house-slug}
|   - Own domain (Business plan): the house points its domain here and verifies it in Settings › Website.
*/
return [
    'domain' => env('SITES_DOMAIN'),                 // e.g. fashionhouse.ng  (blank = subdomains off; /s/{slug} still works)

    // Where a house's own domain should point (shown in the DNS instructions).
    'cname_target' => env('SITES_CNAME_TARGET'),     // e.g. sites.fashionhouse.ng
    'server_ip'    => env('SITES_SERVER_IP'),        // e.g. 203.0.113.10

    // Subdomains a house cannot take.
    // If the platform itself runs on a subdomain of SITES_DOMAIN (APP_URL=https://app.example.ng with
    // SITES_DOMAIN=example.ng), that label is reserved too, so the platform's own pages are never a house site.
    'reserved' => array_values(array_unique(array_filter([
        'www', 'app', 'api', 'admin', 'mail', 'platform', 'billing', 'help', 'support', 'static', 'cdn', 'demo', 's',
        (function () {
            $app = strtolower((string) parse_url((string) env('APP_URL'), PHP_URL_HOST));
            $base = strtolower((string) env('SITES_DOMAIN'));
            return $base && str_ends_with($app, '.' . $base) ? substr($app, 0, -strlen('.' . $base)) : null;
        })(),
    ]))),
];
