<?php

declare(strict_types=1);

return [
    'url' => env('CRATE_URL'),
    'token' => env('CRATE_TOKEN'),
    'issuer' => [
        'base_url' => env('CRATE_ISSUER_URL', env('CRATE_URL')),
        'service_token' => env('CRATE_SERVICE_TOKEN'),
        'subject_ref' => env('CRATE_ISSUER_SUBJECT_REF'),
        'retries' => (int) env('CRATE_ISSUER_RETRIES', 2),
        'retry_sleep_ms' => (int) env('CRATE_ISSUER_RETRY_SLEEP', 100),
    ],
];
