<?php

declare(strict_types=1);

return [
    'url' => env('CRATE_URL'),
    'token' => env('CRATE_TOKEN'),
    'issuer' => [
        'base_url' => env('CRATE_ISSUER_URL', env('CRATE_URL')),
        'admin_token' => env('CRATE_ADMIN_TOKEN'),
        'retries' => (int) env('CRATE_ISSUER_RETRIES', 2),
        'retry_sleep_ms' => (int) env('CRATE_ISSUER_RETRY_SLEEP', 100),
    ],
];
