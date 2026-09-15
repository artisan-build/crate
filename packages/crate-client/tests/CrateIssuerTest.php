<?php

declare(strict_types=1);

use ArtisanBuild\CrateClient\CrateIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('issues fixed-purpose installation Basic credentials through the unified API', function (): void {
    $expiresAt = CarbonImmutable::parse('2026-08-01T12:00:00+00:00');
    Http::fake(['https://crate.example.com/bfc/credentials' => Http::response([
        'credential' => ['id' => 'credential-uuid', 'kind' => 'basic', 'purpose' => 'consumption'],
        'delivery' => ['shape' => 'basic_auth', 'username' => 'credential-uuid', 'password' => 'secret'],
    ], 201)]);

    $result = CrateIssuer::fromConfig()->issue('build bot', $expiresAt);

    expect($result['credential']['id'])->toBe('credential-uuid')
        ->and($result['delivery']['password'])->toBe('secret');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crate.example.com/bfc/credentials'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer service_secret')
        && $request['subject_type'] === 'installation'
        && $request['subject_ref'] === 'customer-builds'
        && $request['kind'] === CrateIssuer::CREDENTIAL_KIND
        && $request['purpose'] === CrateIssuer::CREDENTIAL_PURPOSE
        && $request['name'] === 'build bot'
        && $request['expires_at'] === $expiresAt->toIso8601String());
});

it('rotates and revokes credentials by stable id', function (): void {
    Http::fake([
        'https://crate.example.com/bfc/credentials/credential-uuid/rotate' => Http::response([
            'credential' => ['id' => 'replacement-uuid'],
            'superseded_id' => 'credential-uuid',
            'delivery' => ['shape' => 'basic_auth', 'password' => 'replacement-secret'],
        ], 201),
        'https://crate.example.com/bfc/credentials/replacement-uuid' => Http::response(null, 204),
    ]);

    $result = CrateIssuer::fromConfig()->rotate('credential-uuid');
    CrateIssuer::fromConfig()->revoke('replacement-uuid');

    expect($result['superseded_id'])->toBe('credential-uuid');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crate.example.com/bfc/credentials/credential-uuid/rotate');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crate.example.com/bfc/credentials/replacement-uuid'
        && $request->method() === 'DELETE');
});

it('lists unified credential metadata without a legacy projection', function (): void {
    $row = ['id' => 'credential-uuid', 'kind' => 'basic', 'purpose' => 'consumption', 'status' => 'active'];
    Http::fake(['https://crate.example.com/bfc/credentials' => Http::response([$row])]);

    expect(CrateIssuer::fromConfig()->list()->all())->toBe([$row]);
});

it('throws when the unified credential API fails', function (): void {
    Http::fake(['https://crate.example.com/bfc/credentials' => Http::response([], 500)]);

    CrateIssuer::fromConfig()->issue('build bot');
})->throws(RequestException::class);

it('retries credential issuing', function (): void {
    Http::fake(['https://crate.example.com/bfc/credentials' => Http::sequence()
        ->push([], 500)
        ->push(['credential' => ['id' => 'credential-uuid'], 'delivery' => ['shape' => 'basic_auth']], 201)]);

    expect(CrateIssuer::fromConfig()->issue('build bot')['credential']['id'])->toBe('credential-uuid');
    Http::assertSentCount(2);
});
