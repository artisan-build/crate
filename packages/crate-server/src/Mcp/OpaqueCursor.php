<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateServer\Mcp;

use Illuminate\Validation\ValidationException;
use JsonException;

final class OpaqueCursor
{
    public static function encode(string $scope, int $id): string
    {
        $json = json_encode(['v' => 1, 'scope' => $scope, 'id' => $id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor, string $scope): ?int
    {
        if ($cursor === null) {
            return null;
        }

        $encoded = strtr($cursor, '-_', '+/');
        $padding = strlen($encoded) % 4;

        if ($cursor === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            self::invalid();
        }

        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);

        try {
            $payload = is_string($decoded) ? json_decode($decoded, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            self::invalid();
        }

        if (! is_array($payload)
            || array_keys($payload) !== ['v', 'scope', 'id']
            || $payload['v'] !== 1
            || $payload['scope'] !== $scope
            || ! is_int($payload['id'])
            || $payload['id'] < 1
            || self::encode($scope, $payload['id']) !== $cursor) {
            self::invalid();
        }

        return $payload['id'];
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['cursor' => 'The cursor is invalid.']);
    }
}
