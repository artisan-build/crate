<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\CrateCredentialDeclaration;

return [
    'manifest' => [
        'name' => 'Crate',
        'slug' => 'crate',
        'description' => 'Self-hosted, unmetered private Composer registry for Laravel.',
        'icon' => 'https://raw.githubusercontent.com/artisan-build/crate/main/public/favicon.svg',
        'product_url' => 'https://scalpels.app/products/crate',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => CrateCredentialDeclaration::class,
        'session_guard' => null,
        'app_purposes' => [
            CrateCredentialDeclaration::COMPOSER_PURPOSE => 'consumption',
        ],
    ],

    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => true,
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => [CrateCredentialDeclaration::COMPOSER_PURPOSE],
    ],
];
