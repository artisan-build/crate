<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateClient;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class CrateIssuer
{
    public const string CREDENTIAL_KIND = 'basic';

    public const string CREDENTIAL_PURPOSE = 'consumption';

    private const string ENDPOINT = '/bfc/credentials';

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $serviceToken,
        private readonly string $subjectRef,
        private readonly int $retries = 2,
        private readonly int $retrySleepMs = 100,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('crate-client.issuer.base_url'),
            (string) config('crate-client.issuer.service_token'),
            (string) config('crate-client.issuer.subject_ref'),
            (int) config('crate-client.issuer.retries', 2),
            (int) config('crate-client.issuer.retry_sleep_ms', 100),
        );
    }

    /** @return array<string, mixed> */
    public function issue(string $name, ?CarbonInterface $expiresAt = null): array
    {
        /** @var array<string, mixed> */
        return $this->request()->post(self::ENDPOINT, [
            'subject_type' => 'installation',
            'subject_ref' => $this->subjectRef,
            'kind' => self::CREDENTIAL_KIND,
            'purpose' => self::CREDENTIAL_PURPOSE,
            'name' => $name,
            'expires_at' => $expiresAt?->toIso8601String(),
        ])->throw()->json();
    }

    /** @return array<string, mixed> */
    public function rotate(string $credentialId, bool $emergency = false): array
    {
        /** @var array<string, mixed> */
        return $this->request()
            ->post(self::ENDPOINT.'/'.rawurlencode($credentialId).'/rotate', ['emergency' => $emergency])
            ->throw()
            ->json();
    }

    public function revoke(string $credentialId): void
    {
        $this->request()->delete(self::ENDPOINT.'/'.rawurlencode($credentialId))->throw();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function list(): Collection
    {
        /** @var list<array<string, mixed>> $credentials */
        $credentials = $this->request()->get(self::ENDPOINT)->throw()->json();

        return collect($credentials);
    }

    private function request(): PendingRequest
    {
        return Http::withClientIdentity()
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->serviceToken)
            ->acceptJson()
            ->retry($this->retries, $this->retrySleepMs);
    }
}
