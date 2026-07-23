<?php

return [
    'enabled' => env('MANAGED_DNS_ENABLED', true),
    'reconcile_interval_minutes' => (int) env('MANAGED_DNS_RECONCILE_INTERVAL', 30),
    'cloudflare' => [
        'base_url' => env('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CLOUDFLARE_API_TIMEOUT', 15),
    ],
];
