<?php

declare(strict_types=1);

use ArtisanBuild\CrateClient\CrateIssuer;
use ArtisanBuild\CrateContracts\Credential;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('issues credentials through the bearer admin api', function (): void {
    $expiresAt = CarbonImmutable::parse('2026-08-01T12:00:00+00:00');

    Http::fake([
        'crate.example.com/api/credentials' => Http::response([
            'name' => 'build-bot',
            'plaintext' => 'ctok_new_secret',
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201),
    ]);

    $credential = CrateIssuer::fromConfig()->issue('build-bot', $expiresAt);

    expect($credential)->toBeInstanceOf(Credential::class)
        ->and($credential->name)->toBe('build-bot')
        ->and($credential->plaintext)->toBe('ctok_new_secret')
        ->and($credential->expiresAt)->toBe($expiresAt->toIso8601String());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crate.example.com/api/credentials'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer admin_secret')
        && $request['name'] === 'build-bot'
        && $request['expires_at'] === $expiresAt->toIso8601String());
});

it('throws when credential issuing fails', function (): void {
    Http::fake([
        'crate.example.com/api/credentials' => Http::response([], 500),
    ]);

    CrateIssuer::fromConfig()->issue('build-bot');
})->throws(RequestException::class);

it('revokes credentials through the bearer admin api', function (): void {
    Http::fake([
        'crate.example.com/api/credentials/build-bot' => Http::response(null, 204),
    ]);

    CrateIssuer::fromConfig()->revoke('build-bot');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crate.example.com/api/credentials/build-bot'
        && $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer admin_secret'));
});

it('throws when credential revocation fails', function (): void {
    Http::fake([
        'crate.example.com/api/credentials/build-bot' => Http::response([], 500),
    ]);

    CrateIssuer::fromConfig()->revoke('build-bot');
})->throws(RequestException::class);

it('lists credential metadata without plaintext', function (): void {
    Http::fake([
        'crate.example.com/api/credentials' => Http::response([
            [
                'name' => 'build-bot',
                'last_used_at' => null,
                'expires_at' => '2026-08-01T12:00:00+00:00',
                'revoked_at' => null,
            ],
        ], 200),
    ]);

    $credentials = CrateIssuer::fromConfig()->list();

    expect($credentials)->toHaveCount(1)
        ->and($credentials->first())->toBe([
            'name' => 'build-bot',
            'last_used_at' => null,
            'expires_at' => '2026-08-01T12:00:00+00:00',
            'revoked_at' => null,
        ])
        ->and($credentials->contains(fn (array $credential): bool => array_key_exists('plaintext', $credential)))->toBeFalse();
});

it('retries credential issuing', function (): void {
    Http::fake([
        'crate.example.com/api/credentials' => Http::sequence()
            ->push([], 500)
            ->push([
                'name' => 'build-bot',
                'plaintext' => 'ctok_new_secret',
                'expires_at' => null,
            ], 201),
    ]);

    $credential = CrateIssuer::fromConfig()->issue('build-bot');

    expect($credential->plaintext)->toBe('ctok_new_secret');

    Http::assertSentCount(2);
});
