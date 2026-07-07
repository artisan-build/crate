<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateContracts;

use ArtisanBuild\CrateContracts\Exceptions\InvalidServedRepo;
use JsonException;
use stdClass;

final class ServedRepo
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly RepoType $type = RepoType::Vcs,
        public readonly RepoStatus $status = RepoStatus::Pending,
    ) {}

    public static function make(
        string $name,
        string $url,
        RepoType $type = RepoType::Vcs,
        RepoStatus $status = RepoStatus::Pending,
    ): self {
        return new self($name, $url, $type, $status);
    }

    /**
     * @return array{name: string, url: string, type: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'url' => $this->url,
            'type' => $this->type->value,
            'status' => $this->status->value,
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
        $url = self::requiredString($data, 'url');
        $type = RepoType::Vcs;
        $status = RepoStatus::Pending;

        if (array_key_exists('type', $data)) {
            if (! is_string($data['type'])) {
                throw new InvalidServedRepo('The type field must be a string.');
            }

            $type = RepoType::tryFrom($data['type']) ?? throw new InvalidServedRepo('The type field is not a recognized repo type.');
        }

        if (array_key_exists('status', $data)) {
            if (! is_string($data['status'])) {
                throw new InvalidServedRepo('The status field must be a string.');
            }

            $status = RepoStatus::tryFrom($data['status']) ?? throw new InvalidServedRepo('The status field is not a recognized repo status.');
        }

        return new self($name, $url, $type, $status);
    }

    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidServedRepo('The JSON payload is invalid.', previous: $exception);
        }

        if (! $decoded instanceof stdClass) {
            throw new InvalidServedRepo('The JSON payload must be an object.');
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
            throw new InvalidServedRepo("The {$field} field is required.");
        }

        if (! is_string($data[$field])) {
            throw new InvalidServedRepo("The {$field} field must be a string.");
        }

        if ($data[$field] === '') {
            throw new InvalidServedRepo("The {$field} field must not be empty.");
        }

        return $data[$field];
    }
}
