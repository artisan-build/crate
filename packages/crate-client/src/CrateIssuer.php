<?php

declare(strict_types=1);

namespace ArtisanBuild\CrateClient;

use ArtisanBuild\CrateContracts\Credential;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

final class CrateIssuer
{
    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $adminToken,
        private readonly int $retries = 2,
        private readonly int $retrySleepMs = 100,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('crate-client.issuer.base_url'),
            (string) config('crate-client.issuer.admin_token'),
            (int) config('crate-client.issuer.retries', 2),
            (int) config('crate-client.issuer.retry_sleep_ms', 100),
        );
    }

    public function issue(string $name, ?CarbonInterface $expiresAt = null): Credential
    {
        $response = $this->request()
            ->post('/api/credentials', [
                'name' => $name,
                'expires_at' => $expiresAt?->toIso8601String(),
            ])
            ->throw();

        /** @var array<string, mixed> $credential */
        $credential = $response->json();

        return Credential::fromArray($credential);
    }

    public function revoke(string $name): void
    {
        $this->request()
            ->delete('/api/credentials/'.rawurlencode($name))
            ->throw();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        /** @var list<array<string, mixed>> $credentials */
        $credentials = $this->request()
            ->get('/api/credentials')
            ->throw()
            ->json();

        return collect($credentials);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->withToken($this->adminToken)
            ->acceptJson()
            ->retry($this->retries, $this->retrySleepMs);
    }
}
