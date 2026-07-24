<?php

return [
    // URL of the agent installer script (produced by the reverse-proxy agent
    // project's release pipeline). The generated install command curls this and
    // pipes it to bash with the node's credentials as flags.
    'installer_url' => env('REVERSE_PROXY_INSTALLER_URL', 'https://github.com/ajjswift/reverse-proxy/releases/latest/download/install.sh'),

    // Contact email used when requesting Let's Encrypt certificates.
    'letsencrypt_email' => env('REVERSE_PROXY_LETSENCRYPT_EMAIL'),

    // How long (seconds) before a node's agent is considered offline if it has
    // not sent a status callback.
    'offline_after' => env('REVERSE_PROXY_OFFLINE_AFTER', 120),
];
