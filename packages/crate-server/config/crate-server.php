<?php

declare(strict_types=1);

return [
    'database' => [
        'connection' => 'crate',
        'host' => env('CRATE_DB_HOST'),
        'port' => env('CRATE_DB_PORT'),
        'database' => env('CRATE_DB_DATABASE'),
        'username' => env('CRATE_DB_USERNAME'),
        'password' => env('CRATE_DB_PASSWORD'),
    ],
];
