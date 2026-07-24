<?php

return [
    'enabled' => env('MANAGED_DNS_ENABLED', true),
    'reconcile_interval_minutes' => (int) env('MANAGED_DNS_RECONCILE_INTERVAL', 30),
    'http_detection' => [
        // Keep previews responsive while still allowing a node on another
        // network enough time to answer the protocol probe.
        'connect_timeout' => (float) env('MANAGED_DNS_HTTP_CONNECT_TIMEOUT', 1),
        'timeout' => (float) env('MANAGED_DNS_HTTP_TIMEOUT', 2),
        'positive_ttl' => (int) env('MANAGED_DNS_HTTP_POSITIVE_TTL', 300),
        'negative_ttl' => (int) env('MANAGED_DNS_HTTP_NEGATIVE_TTL', 30),
    ],
    'cloudflare' => [
        'base_url' => env('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CLOUDFLARE_API_TIMEOUT', 15),
    ],
];
