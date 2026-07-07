<?php

declare(strict_types=1);

use ArtisanBuild\CrateContracts\Credential;
use ArtisanBuild\CrateContracts\Exceptions\InvalidCredential;

it('round trips a minimal credential through arrays and JSON', function (): void {
    $credential = Credential::make(
        name: 'deploy-token',
        plaintext: 'secret-token',
    );

    $fromArray = Credential::fromArray($credential->toArray());
    $fromJson = Credential::fromJson($credential->toJson());

    expect($credential->toArray())->toBe([
        'name' => 'deploy-token',
        'plaintext' => 'secret-token',
        'expires_at' => null,
    ])
        ->and($fromArray)->toEqual($credential)
        ->and($fromJson)->toEqual($credential);
});

it('round trips a full credential through arrays and JSON', function (): void {
    $credential = Credential::make(
        name: 'ci-token',
        plaintext: 'plain-secret',
        expiresAt: '2026-07-08T12:00:00+00:00',
    );

    $fromArray = Credential::fromArray($credential->toArray());
    $fromJson = Credential::fromJson($credential->toJson());

    expect($fromArray->name)->toBe($credential->name)
        ->and($fromArray->plaintext)->toBe($credential->plaintext)
        ->and($fromArray->expiresAt)->toBe($credential->expiresAt)
        ->and($fromJson)->toEqual($credential);
});

it('emits exact credential wire keys and preserves null expiry', function (): void {
    $credential = Credential::make(
        name: 'deploy-token',
        plaintext: 'secret-token',
    );

    expect(array_keys($credential->toArray()))->toBe(['name', 'plaintext', 'expires_at'])
        ->and($credential->toArray()['expires_at'])->toBeNull();
});

it('ignores unknown credential keys and defaults optional fields', function (): void {
    $credential = Credential::fromArray([
        'name' => 'deploy-token',
        'plaintext' => 'secret-token',
        'unknown' => 'ignored',
    ]);

    expect($credential->expiresAt)->toBeNull();
});

it('rejects malformed credential arrays', function (array $data): void {
    Credential::fromArray($data);
})->throws(InvalidCredential::class)->with('malformed credentials');

it('rejects malformed credential JSON', function (string $json): void {
    Credential::fromJson($json);
})->throws(InvalidCredential::class)->with('malformed credential JSON');

dataset('malformed credentials', [
    'missing name' => [['plaintext' => 'secret-token']],
    'empty name' => [['name' => '', 'plaintext' => 'secret-token']],
    'wrong-type name' => [['name' => 5, 'plaintext' => 'secret-token']],
    'missing plaintext' => [['name' => 'deploy-token']],
    'empty plaintext' => [['name' => 'deploy-token', 'plaintext' => '']],
    'wrong-type plaintext' => [['name' => 'deploy-token', 'plaintext' => false]],
    'wrong-type expires_at' => [['name' => 'deploy-token', 'plaintext' => 'secret-token', 'expires_at' => 5]],
]);

dataset('malformed credential JSON', [
    'invalid JSON' => ['{'],
    'empty list' => ['[]'],
    'list' => ['[1,2]'],
    'scalar' => ['5'],
]);
