<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\TokenRegistry;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

function crateServerStoreToken(string $plaintext, ?DateTimeInterface $expiresAt = null): void
{
    app(TokenRegistry::class)->store(
        name: 'test-'.str_replace('.', '-', uniqid('', true)),
        hash: hash('sha256', $plaintext),
        expiresAt: $expiresAt,
    );
}

function crateServerAuthenticatedGet(string $uri, string $plaintext): TestResponse
{
    return test()->withBasicAuth('token', $plaintext)->get($uri);
}

beforeEach(function (): void {
    Storage::fake('crate-archive');
    Storage::disk('crate-archive')->put('satis/packages.json', '{"packages":[]}');
    Storage::disk('crate-archive')->put('satis/p2/vendor/package.json', '{"packages":{"vendor/package":[]}}');
    Storage::disk('crate-archive')->put('satis/dist/vendor/package/1.0.0.zip', 'zip-bytes');
});

it('serves registry metadata and dist archives for a valid credential', function (): void {
    crateServerStoreToken('valid-secret');

    $packages = crateServerAuthenticatedGet('/packages.json', 'valid-secret')
        ->assertOk();

    $provider = crateServerAuthenticatedGet('/p2/vendor/package.json', 'valid-secret')
        ->assertOk();

    $dist = crateServerAuthenticatedGet('/dist/vendor/package/1.0.0.zip', 'valid-secret')
        ->assertOk();

    expect($packages->streamedContent())->toBe('{"packages":[]}')
        ->and($provider->streamedContent())->toBe('{"packages":{"vendor/package":[]}}')
        ->and($dist->streamedContent())->toBe('zip-bytes');
});

it('rejects missing and unknown credentials', function (): void {
    $this->get('/packages.json')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Crate"');

    crateServerAuthenticatedGet('/packages.json', 'unknown-secret')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Crate"');
});

it('rejects expired credentials', function (): void {
    crateServerStoreToken('expired-secret', now()->subMinute());

    expect(app(TokenRegistry::class)->resolve('expired-secret'))->toBeNull();

    crateServerAuthenticatedGet('/packages.json', 'expired-secret')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="Crate"');
});

it('returns a 404 for a missing file with a valid credential', function (): void {
    crateServerStoreToken('valid-secret');

    crateServerAuthenticatedGet('/p2/vendor/missing.json', 'valid-secret')
        ->assertNotFound();
});
