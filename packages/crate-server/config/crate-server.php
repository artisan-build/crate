<?php

declare(strict_types=1);

return [
    'url' => env('CRATE_URL'),
    'archive_disk' => env('CRATE_ARCHIVE_DISK', env('FILESYSTEM_DISK', 'local')),
    // The Satis EXECUTABLE (BuildSatis runs this path directly), not an install
    // directory. The default only exists if Satis is required into the app
    // vendor tree; isolated installs must set CRATE_SATIS_PATH to
    // <install-dir>/bin/satis. See docs/deploy.md.
    'satis_path' => env('CRATE_SATIS_PATH', base_path('vendor/bin/satis')),
    'output_dir' => env('CRATE_OUTPUT_DIR', 'satis'),

    'database' => [
        'connection' => 'crate',
        'host' => env('CRATE_DB_HOST'),
        'port' => env('CRATE_DB_PORT'),
        'database' => env('CRATE_DB_DATABASE'),
        'username' => env('CRATE_DB_USERNAME'),
        'password' => env('CRATE_DB_PASSWORD'),
    ],
];
