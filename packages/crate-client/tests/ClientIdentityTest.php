<?php

declare(strict_types=1);

use ArtisanBuild\BfcClient\BfcHeaders;
use ArtisanBuild\CrateClient\CrateIssuer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('attaches canonical client identity metadata to every issuer verb', function (): void {
    Http::fake([
        'https://crate.example.com/bfc/credentials' => Http::response(['credential' => [], 'delivery' => []], 201),
        'https://crate.example.com/bfc/credentials/*/rotate' => Http::response(['credential' => [], 'delivery' => []], 201),
        'https://crate.example.com/bfc/credentials/*' => Http::response(null, 204),
    ]);

    $issuer = CrateIssuer::fromConfig();
    $issuer->issue('build bot');
    $issuer->list();
    $issuer->rotate('credential-uuid');
    $issuer->revoke('credential-uuid');

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['crate-install-abc123']
        && $request->hasHeader('Authorization', 'Bearer service_secret'));
});

it('treats client identity as metadata rather than request authority', function (): void {
    config()->set('bfc-client.identity', 'some-other-install');
    Http::fake(['https://crate.example.com/bfc/credentials' => Http::response([])]);

    CrateIssuer::fromConfig()->list();

    Http::assertSent(fn (Request $request): bool => $request->header(BfcHeaders::CLIENT_ID) === ['some-other-install']
        && $request->hasHeader('Authorization', 'Bearer service_secret'));
});
