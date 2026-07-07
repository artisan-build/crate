<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateContracts;

use ArtisanBuild\CrateContracts\Exceptions\InvalidCredential;
use JsonException;
use stdClass;

final class Credential
{
    public function __construct(
        public readonly string $name,
        public readonly string $plaintext,
        public readonly ?string $expiresAt = null,
    ) {}

    public static function make(
        string $name,
        string $plaintext,
        ?string $expiresAt = null,
    ): self {
        return new self($name, $plaintext, $expiresAt);
    }

    /**
     * @return array{name: string, plaintext: string, expires_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'plaintext' => $this->plaintext,
            'expires_at' => $this->expiresAt,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $name = self::requiredString($data, 'name');
        $plaintext = self::requiredString($data, 'plaintext');
        $expiresAt = null;

        if (array_key_exists('expires_at', $data)) {
            if (! is_string($data['expires_at']) && $data['expires_at'] !== null) {
                throw new InvalidCredential('The expires_at field must be a string or null.');
            }

            $expiresAt = $data['expires_at'];
        }

        return new self($name, $plaintext, $expiresAt);
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidCredential('The JSON payload is invalid.', previous: $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidCredential('The JSON payload must be an object.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $field): string
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidCredential("The {$field} field is required.");
        }

        if (! is_string($data[$field])) {
            throw new InvalidCredential("The {$field} field must be a string.");
        }

        if ($data[$field] === '') {
            throw new InvalidCredential("The {$field} field must not be empty.");
        }

        return $data[$field];
    }
}
