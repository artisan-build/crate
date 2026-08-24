<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcHeaders;
use ArtisanBuild\CrateClient\CrateIssuer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends the BfC client identity header when issuing a credential', function (): void {
    Http::fake([
        'crate.example.com/api/credentials' => Http::response([
            'name' => 'build-bot',
            'plaintext' => 'ctok_new_secret',
            'expires_at' => null,
        ], 201),
    ]);

    CrateIssuer::fromConfig()->issue('build-bot');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://crate.example.com/api/credentials'
        && $request->header(BfcHeaders::CLIENT_ID) === ['crate-install-abc123']
        && $request->hasHeader('Authorization', 'Bearer admin_secret'));
});

it('sends the BfC client identity header when revoking a credential', function (): void {
    Http::fake([
        'crate.example.com/api/credentials/build-bot' => Http::response(null, 204),
    ]);

    CrateIssuer::fromConfig()->revoke('build-bot');

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['crate-install-abc123']);
});

it('sends the BfC client identity header when listing credentials', function (): void {
    Http::fake([
        'crate.example.com/api/credentials' => Http::response([], 200),
    ]);

    CrateIssuer::fromConfig()->list();

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['crate-install-abc123']);
});

it('resolves the identity through bfc-client rather than hard-coding it', function (): void {
    config()->set('bfc-client.identity', 'some-other-install');

    Http::fake([
        'crate.example.com/api/credentials' => Http::response([], 200),
    ]);

    CrateIssuer::fromConfig()->list();

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['some-other-install']);
});
