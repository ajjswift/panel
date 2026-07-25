<?php

return [
    'enabled' => env('MANAGED_DNS_ENABLED', true),
    'reconcile_interval_minutes' => (int) env('MANAGED_DNS_RECONCILE_INTERVAL', 30),
    'http_detection' => [
        // An HTTP request to the game port decides whether a subdomain is
        // routed through the node's reverse proxy (a website) or plain DNS.
        // Keep it responsive while still giving a node on another network
        // enough time to answer.
        'connect_timeout' => (float) env('MANAGED_DNS_HTTP_CONNECT_TIMEOUT', 1),
        'timeout' => (float) env('MANAGED_DNS_HTTP_TIMEOUT', 2),
        'positive_ttl' => (int) env('MANAGED_DNS_HTTP_POSITIVE_TTL', 300),
        'negative_ttl' => (int) env('MANAGED_DNS_HTTP_NEGATIVE_TTL', 30),
    ],
    'minecraft_detection' => [
        // A Minecraft status handshake against the port decides whether a
        // subdomain gets a clean, port-free SRV record.
        'connect_timeout' => (float) env('MANAGED_DNS_MINECRAFT_CONNECT_TIMEOUT', 1),
        'timeout' => (int) env('MANAGED_DNS_MINECRAFT_TIMEOUT', 2),
        'positive_ttl' => (int) env('MANAGED_DNS_MINECRAFT_POSITIVE_TTL', 300),
        'negative_ttl' => (int) env('MANAGED_DNS_MINECRAFT_NEGATIVE_TTL', 30),
    ],
    'cloudflare' => [
        'base_url' => env('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('CLOUDFLARE_API_TIMEOUT', 15),
    ],
];
