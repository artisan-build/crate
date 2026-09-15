<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\CrateServer\Models\Build;
use ArtisanBuild\CrateServer\Models\ServedRepo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

$appDirectory = getenv('APP_DIR');
if (! is_string($appDirectory) || $appDirectory === '') {
    fwrite(STDERR, "APP_DIR is required\n");
    exit(1);
}

require $appDirectory.'/vendor/autoload.php';
$app = require $appDirectory.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = $argv[1] ?? '';
$buildDiagnostic = static function (): string {
    $build = Build::query()->latest('id')->first();
    $repo = ServedRepo::query()->orderByDesc('id')->first();

    return json_encode([
        'build_status' => $build?->status->value ?? 'missing',
        'build_output' => $build?->output ?? '',
        'repository_status' => $repo?->status->value ?? 'missing',
        'archive_exists' => Storage::disk((string) config('crate-server.archive_disk'))->exists('satis/packages.json'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
};

if ($operation === 'count-repositories') {
    echo ServedRepo::query()->count();
    exit(0);
}

if ($operation === 'build-state') {
    $build = Build::query()->latest('id')->first();
    echo $build?->status->value ?? 'missing';
    exit(0);
}

if ($operation === 'latest-build-id') {
    echo Build::query()->max('id') ?? 0;
    exit(0);
}

if ($operation === 'build-state-after') {
    $buildId = $argv[2] ?? '';
    if (! ctype_digit($buildId)) {
        fwrite(STDERR, "build-state-after requires a build ID\n");
        exit(1);
    }

    $build = Build::query()->where('id', '>', (int) $buildId)->latest('id')->first();
    echo $build?->status->value ?? 'pending';
    exit(0);
}

if (in_array($operation, ['assert-source-secret-stored', 'assert-source-secret-cleared', 'assert-source-build-safe'], true)) {
    $master = getenv('CRATE_C1_FIXTURE_SECRET');
    $workDirectory = getenv('WORK_DIR');
    if (! is_string($master) || $master === '' || ! is_string($workDirectory) || $workDirectory === '') {
        fwrite(STDERR, "source-secret inspection environment is incomplete\n");
        exit(1);
    }

    $secret = hash_hmac('sha256', 'source-credential', $master);
    $repo = ServedRepo::query()->where('name', 'crate-c1/fixture')->firstOrFail();
    $raw = DB::connection('crate')->table('served_repos')->where('id', $repo->getKey())->value('source_credential');

    if ($operation === 'assert-source-secret-stored') {
        $response = file_get_contents($workDirectory.'/member-source-put.json');
        if ($repo->source_credential !== $secret
            || ! is_string($raw)
            || hash_equals($secret, $raw)
            || ! is_string($response)
            || str_contains($response, $secret)) {
            fwrite(STDERR, "source credential storage assertion failed\n");
            exit(1);
        }
        exit(0);
    }

    if ($operation === 'assert-source-secret-cleared') {
        $response = file_get_contents($workDirectory.'/member-source-delete.txt');
        if ($repo->source_credential !== null
            || $raw !== null
            || ! is_string($response)
            || str_contains($response, $secret)) {
            fwrite(STDERR, "source credential removal assertion failed\n");
            exit(1);
        }
        exit(0);
    }

    $build = Build::query()->latest('id')->firstOrFail();
    $temporaryAuth = glob(storage_path('framework/cache/crate-satis/*/auth.json'));
    if (str_contains((string) $build->output, $secret) || (is_array($temporaryAuth) && $temporaryAuth !== [])) {
        fwrite(STDERR, "source credential retained by build\n");
        exit(1);
    }
    exit(0);
}

if ($operation === 'build-diagnostic') {
    echo $buildDiagnostic();
    exit(0);
}

if ($operation === 'assert-registry-built') {
    $build = Build::query()->latest('id')->firstOrFail();
    if ($build->status->value !== 'succeeded'
        || ! Storage::disk((string) config('crate-server.archive_disk'))->exists('satis/packages.json')) {
        fwrite(STDERR, "registry build is incomplete:\n");
        fwrite(STDERR, $buildDiagnostic());
        exit(1);
    }
    exit(0);
}

if ($operation !== 'substrate') {
    fwrite(STDERR, "Unknown seed operation: {$operation}\n");
    exit(1);
}

$statePath = getenv('CRATE_C1_AUTHORITY_STATE');
if (! is_string($statePath) || $statePath === '') {
    fwrite(STDERR, "CRATE_C1_AUTHORITY_STATE is required\n");
    exit(1);
}

file_put_contents(
    $statePath,
    json_encode(['responses' => [], 'requests' => []], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    LOCK_EX,
);

if (DB::connection()->getDriverName() !== 'pgsql'
    || ! Schema::hasTable('users')
    || ! Schema::hasTable('credentials')
    || ! Schema::connection('crate')->hasTable('served_repos')
    || class_exists('App\\Models\\User')
    || is_file($appDirectory.'/database/migrations/0001_01_01_000000_create_users_table.php')) {
    fwrite(STDERR, "fresh thin-host schema assertion failed\n");
    exit(1);
}

DB::table('bfc_authority')->updateOrInsert(
    ['key' => InstallationAuthority::KEY],
    [
        'mode' => AuthorityMode::Standalone->value,
        'generation' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ],
);

$master = getenv('CRATE_C1_FIXTURE_SECRET');
if (! is_string($master) || $master === '') {
    fwrite(STDERR, "CRATE_C1_FIXTURE_SECRET is required\n");
    exit(1);
}

foreach (UserRole::cases() as $role) {
    $user = User::query()->create([
        'name' => 'C1 '.ucfirst($role->value),
        'email' => $role->value.'@crate-c1.example.test',
        'password' => Hash::make(hash_hmac('sha256', 'password-'.$role->value, $master)),
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
        'original_contact_email' => $user->email,
    ])->save();

    $secret = hash_hmac('sha256', 'personal-'.$role->value, $master);
    Credential::query()->create([
        'kind' => CredentialKind::Basic,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'crate-user:'.$user->getKey(),
        'user_id' => (string) $user->getKey(),
        'name' => 'c1-personal-'.$role->value,
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
    ]);
}

$installationSecret = hash_hmac('sha256', 'installation', $master);
Credential::query()->create([
    'kind' => CredentialKind::Basic,
    'purpose' => CredentialPurpose::Consumption,
    'subject_type' => SubjectType::Installation,
    'subject_ref' => 'deployment-automation',
    'name' => 'c1-installation',
    'status' => CredentialStatus::Active,
    'secret_hash' => hash('sha256', $installationSecret),
]);

$wrongPurpose = hash_hmac('sha256', 'wrong-purpose', $master);
Credential::query()->create([
    'kind' => CredentialKind::Basic,
    'purpose' => CredentialPurpose::SystemDeployment,
    'subject_type' => SubjectType::Installation,
    'subject_ref' => 'wrong-purpose-automation',
    'name' => 'c1-wrong-purpose',
    'status' => CredentialStatus::Active,
    'secret_hash' => hash('sha256', $wrongPurpose),
]);

$revoked = hash_hmac('sha256', 'revoked', $master);
Credential::query()->create([
    'kind' => CredentialKind::Basic,
    'purpose' => CredentialPurpose::Consumption,
    'subject_type' => SubjectType::Installation,
    'subject_ref' => 'revoked-automation',
    'name' => 'c1-revoked',
    'status' => CredentialStatus::Active,
    'secret_hash' => hash('sha256', $revoked),
    'revoked_at' => now(),
]);

echo "seeded substrate\n";
