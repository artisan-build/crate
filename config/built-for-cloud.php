<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\CrateCredentialDeclaration;

return [
    'manifest' => [
        'name' => 'Crate',
        'slug' => 'crate',
        'description' => 'Self-hosted, unmetered private Composer registry for Laravel.',
        'icon' => 'https://scalpels.app/img/products/transparent/crate.png',
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

    'dashboard_prompts' => [
        [
            'title' => 'Serve a new package',
            'ask' => 'Serve the GitHub repository I name from Crate. Read https://scalpels.app/docs/crate/serving-packages#let-your-agent-add-packages and follow its steps with the Laravel Cloud CLI. Never ask me to paste a token into the chat.',
        ],
        [
            'title' => 'Add a private package to this project',
            'ask' => 'Get the composer.json repository entry for Crate, add it to this project, check that Crate serves the package I name, and require it.',
        ],
        [
            'title' => 'Give CI access to my packages',
            'ask' => 'Create a project credential for this repository and store it as the COMPOSER_AUTH secret in GitHub Actions, redeeming the code inside the command that stores it so the credential is never printed.',
        ],
        [
            'title' => 'Give a deployment access to my packages',
            'ask' => 'Create a project credential for this app and set it as the COMPOSER_AUTH environment variable in its Laravel Cloud or Forge environment, without ever printing the credential.',
        ],
        [
            'title' => 'Set up my machine',
            'ask' => 'Tell me how to connect this machine to Crate so Composer can install my private packages.',
        ],
        [
            'title' => 'Which packages are served?',
            'ask' => 'List the repositories Crate serves, when each was last built, and flag any that failed or have not built recently.',
        ],
        [
            'title' => 'Stop serving a package',
            'ask' => 'Stop serving the package I name from Crate. Remove it with the Laravel Cloud CLI as https://scalpels.app/docs/crate/serving-packages#let-your-agent-add-packages describes, then confirm the rebuild succeeded.',
        ],
        [
            'title' => 'Rebuild the registry',
            'ask' => 'Trigger a Crate build and tell me when it finishes and whether it succeeded.',
        ],
    ],
];
