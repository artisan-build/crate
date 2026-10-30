<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\DelegatedClaims;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\CrateServer\Actions\IssueExchangeCode;
use ArtisanBuild\CrateServer\Mcp\Tools\ComposerRepositoryTool;
use ArtisanBuild\CrateServer\Mcp\Tools\CreateProjectCredentialTool;
use ArtisanBuild\CrateServer\Models\ExchangeCode;
use ArtisanBuild\CrateServer\RegistryAddress;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

beforeEach(function (): void {
    config(['crate-server.url' => 'https://crate.example.test']);
    URL::forceRootUrl('https://crate.example.test');
    Storage::fake('crate-archive');
    Storage::disk('crate-archive')->put('satis/packages.json', '{"packages":[]}');
    Storage::disk('crate-archive')->put('satis/p2/acme/private-package.json', '{"packages":{"acme/private-package":[]}}');
});

it('returns the composer.json repository entry and confirms which packages are served', function (): void {
    exchangeActAs(ConsoleRole::Member);

    $served = exchangeToolJson(app(ComposerRepositoryTool::class)->handle(
        new Request(['package' => 'acme/private-package']),
        app(RegistryAddress::class),
    ));
    $missing = exchangeToolJson(app(ComposerRepositoryTool::class)->handle(
        new Request(['package' => 'acme/unknown']),
        app(RegistryAddress::class),
    ));

    expect($served['composer_json'])->toBe([
        'repositories' => ['crate' => ['type' => 'composer', 'url' => 'https://crate.example.test']],
    ])
        ->and($served['commands'])->toBe([
            'composer config repositories.crate composer https://crate.example.test',
            'composer require acme/private-package',
        ])
        ->and($served['package'])->toBe(['name' => 'acme/private-package', 'served' => true])
        ->and($served['auth']['http_basic_host'])->toBe('crate.example.test')
        ->and($missing['package'])->toBe(['name' => 'acme/unknown', 'served' => false]);
});

it('gives a Scalpels admin a project exchange code but never the credential', function (): void {
    exchangeActAs(ConsoleRole::Admin);

    $result = exchangeToolJson(exchangeCreateProjectCredential('acme/storefront'));
    $exchange = ExchangeCode::query()->sole();

    expect($result['project'])->toBe('acme/storefront')
        ->and($result['redeem']['url'])->toStartWith('https://crate.example.test/exchange/crx_')
        ->and($result['store_examples']['github_actions_secret'])->toContain('gh secret set COMPOSER_AUTH')
        ->and(json_encode($result))->not->toContain('password')
        ->and($exchange->project)->toBe('acme/storefront')
        ->and($exchange->code_hash)->toBe(hash('sha256', basename((string) $result['redeem']['url'])))
        ->and(Credential::query()->count())->toBe(0);
});

it('refuses project credentials to anyone but a delegated Scalpels admin', function (): void {
    exchangeActAs(ConsoleRole::Member);

    expect(exchangeCreateProjectCredential('acme/storefront')->isError())->toBeTrue()
        ->and(ExchangeCode::query()->count())->toBe(0);
});

it('redeems a project code once into a COMPOSER_AUTH document the registry accepts', function (): void {
    $issued = app(IssueExchangeCode::class)->forProject('acme/storefront', 'delegated:operator');

    $auth = $this->post(route('crate.exchange.composer-auth', $issued->code))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('http-basic')['crate.example.test'];

    $this->post(route('crate.exchange.composer-auth', $issued->code))->assertNotFound();

    $credential = Credential::query()->sole();

    expect($credential->subject_type)->toBe(SubjectType::Installation)
        ->and($credential->subject_ref)->toBe('crate-project:acme/storefront')
        ->and($credential->user_id)->toBeNull();

    $this->withBasicAuth($auth['username'], $auth['password'])->get('/packages.json')->assertOk();
});

it('refuses an expired code without minting', function (): void {
    $issued = app(IssueExchangeCode::class)->forProject('acme/storefront', 'delegated:operator');

    $this->travel(IssueExchangeCode::TTL_MINUTES + 1)->minutes();

    $this->post(route('crate.exchange.composer-auth', $issued->code))->assertNotFound();

    expect(Credential::query()->count())->toBe(0);
});

it('connects a signed-in person’s machine with a one-line command bound to them', function (): void {
    $user = exchangeUser();
    $this->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ]);

    $this->get(route('crate.connect'))
        ->assertOk()
        ->assertSeeHtml('data-testid="crate-connect-issue"');
    $page = $this->post(route('crate.connect.store'))
        ->assertCreated()
        ->assertSeeHtml('data-testid="crate-connect-command"')
        ->getContent();

    preg_match('#https://crate\.example\.test/connect/(crx_[A-Za-z0-9]{40})#', (string) $page, $matches);
    $code = $matches[1] ?? '';

    $script = $this->get(route('crate.exchange.script', $code))->assertOk()->getContent();

    expect($script)->toContain('composer config --global --auth "http-basic.crate.example.test"')
        ->and(ExchangeCode::query()->pending()->count())->toBe(1);

    [$username, $password] = explode(' ', trim((string) $this->post(route('crate.exchange.basic', $code))->assertOk()->getContent()));
    $credential = Credential::query()->sole();

    expect($credential->user_id)->toBe((string) $user->getKey())
        ->and($credential->subject_type)->toBe(SubjectType::UserPrincipal)
        ->and($credential->subject_ref)->toBe('crate-user:'.$user->getKey())
        ->and($username)->toBe($credential->getKey());

    $this->withBasicAuth($username, $password)->get('/packages.json')->assertOk();
    $this->get(route('crate.exchange.script', $code))->assertNotFound();
});

it('does not mint a personal credential for someone offboarded after the code was issued', function (): void {
    $user = exchangeUser();
    $issued = app(IssueExchangeCode::class)->forPerson($user);
    $user->forceFill(['status' => 'deactivated'])->save();

    $this->post(route('crate.exchange.basic', $issued->code))->assertNotFound();

    expect(Credential::query()->count())->toBe(0);
});

it('puts Connect this machine in the sidebar of shell pages that bring no navigation of their own', function (): void {
    $shell = Blade::render('<x-bfc-layout>Body</x-bfc-layout>');
    $own = Blade::render(<<<'BLADE'
        <x-bfc-layout>
            <x-slot:sidebar>Own navigation</x-slot:sidebar>
            Body
        </x-bfc-layout>
        BLADE);

    expect($shell)->toContain('data-testid="crate-connect-navigation"')
        ->toContain('href="'.route('crate.connect').'"')
        ->and($own)->toContain('Own navigation')
        ->not->toContain('data-testid="crate-connect-navigation"');
});

function exchangeActAs(ConsoleRole $role): void
{
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('https://scalpels.test', 'operator-'.$role->value),
        'issuer' => 'https://scalpels.test',
        'subject' => 'operator-'.$role->value,
        'last_handoff_display_name' => 'Operator',
        'last_handoff_role' => $role,
    ]);

    app('request')->attributes->set('bfc.request_assertion_principal', ActingPrincipal::delegatedRequest($actor, new DelegatedClaims(
        displayName: 'Operator',
        role: $role,
        onBehalfOf: 'Team',
    )));
}

function exchangeCreateProjectCredential(string $project): Response
{
    return app(CreateProjectCredentialTool::class)->handle(
        new Request(['project' => $project]),
        app(ActingPrincipalResolver::class),
        app(IssueExchangeCode::class),
    );
}

function exchangeUser(): User
{
    $user = User::query()->create([
        'name' => 'Connecting developer',
        'email' => 'connect-'.bin2hex(random_bytes(4)).'@example.test',
    ]);
    $user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user->refresh();
}

/** @return array<string, mixed> */
function exchangeToolJson(Response $response): array
{
    return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
}
