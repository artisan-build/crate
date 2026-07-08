<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\TokenRegistry;

function crateStoreAdminToken(string $plaintext = 'secret-admin'): string
{
    resolve(TokenRegistry::class)->store(
        'ci',
        hash('sha256', $plaintext),
        null,
        ['admin'],
    );

    return $plaintext;
}

it('keeps the credential api admin-token gated', function (): void {
    $this->getJson('/api/credentials')
        ->assertUnauthorized();
});

it('allows an admin token to list and issue credentials', function (): void {
    $plaintext = crateStoreAdminToken();

    $this->withToken($plaintext)
        ->getJson('/api/credentials')
        ->assertOk()
        ->assertJsonIsArray();

    $this->withToken($plaintext)
        ->postJson('/api/credentials', ['name' => 'build-bot'])
        ->assertCreated()
        ->assertJsonPath('name', 'build-bot')
        ->assertJsonStructure(['name', 'plaintext', 'expires_at']);
});
