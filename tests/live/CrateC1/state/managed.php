<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$appDirectory = getenv('APP_DIR');
if (! is_string($appDirectory) || $appDirectory === '') {
    fwrite(STDERR, "APP_DIR is required\n");
    exit(1);
}

require $appDirectory.'/vendor/autoload.php';
$app = require $appDirectory.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = $argv[1] ?? '';
$statusPath = getenv('CRATE_C1_MANAGED_STATUS');
$port = getenv('CRATE_C1_MANAGED_PORT');
if (! is_string($statusPath) || $statusPath === '' || ! is_string($port) || ! ctype_digit($port)) {
    fwrite(STDERR, "managed fixture environment is incomplete\n");
    exit(1);
}

if ($operation === 'activate') {
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Managed->value,
        'generation' => 7,
        'issuer' => 'https://live-issuer.example.test',
        'connection_id' => 'live-connection',
        'organization_id' => 'live-organization',
        'installation_id' => 'live-installation',
        'authority_base_url' => 'https://127.0.0.1:'.$port,
        'managed_connection_status' => 'active',
        'managed_connection_generation' => 7,
        'managed_connection_roster_version' => 10,
        'managed_connection_response_sequence' => 10,
        'updated_at' => now(),
    ]);

    foreach (UserRole::cases() as $role) {
        $user = User::query()->where('email', $role->value.'@crate-c1.example.test')->firstOrFail();
        $user->forceFill([
            'role' => $role->value,
            'status' => 'active',
            'scalpels_issuer' => 'https://live-issuer.example.test',
            'scalpels_connection_id' => 'live-connection',
            'scalpels_id' => 'live-'.$role->value,
            'membership_confirmed_at' => now(),
            'membership_checked_at' => now(),
            'membership_response_at' => now(),
            'managed_membership_status' => 'active',
            'managed_membership_role' => $role->value,
            'managed_membership_generation' => 7,
            'managed_membership_roster_version' => 10,
            'managed_membership_response_sequence' => 10,
            'managed_membership_responded_at' => now(),
        ])->save();
    }
    exit(0);
}

if ($operation === 'set-age') {
    $role = UserRole::tryFrom($argv[2] ?? '');
    $seconds = $argv[3] ?? '';
    if (! $role instanceof UserRole || ! ctype_digit($seconds)) {
        fwrite(STDERR, "set-age requires a role and age\n");
        exit(1);
    }

    $user = User::query()->where('email', $role->value.'@crate-c1.example.test')->firstOrFail();
    $at = now()->subSeconds((int) $seconds);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'managed_membership_status' => 'active',
        'managed_membership_role' => $role->value,
        'membership_confirmed_at' => $at,
        'membership_checked_at' => $at,
        'membership_response_at' => $at,
    ])->save();

    $key = hash('sha256', implode("\0", [
        'https://live-issuer.example.test',
        'live-connection',
        (string) $user->scalpels_id,
    ]));
    Cache::forget('bfc:managed-refresh-attempt:'.$key);
    exit(0);
}

if ($operation === 'confirmation-status') {
    $status = $argv[2] ?? '';
    if (! ctype_digit($status)) {
        fwrite(STDERR, "confirmation-status requires an HTTP status\n");
        exit(1);
    }
    file_put_contents($statusPath.'.confirmation-status', $status, LOCK_EX);
    exit(0);
}

if ($operation === 'membership-response') {
    $status = $argv[2] ?? '';
    if (! in_array($status, ['active', 'removed'], true)) {
        fwrite(STDERR, "membership-response requires active or removed\n");
        exit(1);
    }
    file_put_contents($statusPath.'.response', json_encode(['membership_status' => $status], JSON_THROW_ON_ERROR), LOCK_EX);
    exit(0);
}

if ($operation === 'confirmation-count') {
    $contents = file_get_contents($statusPath);
    $status = is_string($contents) ? json_decode($contents, true) : null;
    echo is_array($status) ? (int) ($status['confirmation_count'] ?? 0) : 0;
    exit(0);
}

if ($operation === 'assert-managed-entry') {
    $user = User::query()->where('email', 'live-fixture@example.test')->firstOrFail();
    if ($user->scalpels_issuer !== 'https://live-issuer.example.test'
        || $user->scalpels_connection_id !== 'live-connection'
        || $user->scalpels_id !== 'live-subject'
        || $user->managed_membership_status !== 'active') {
        fwrite(STDERR, "managed entry assertion failed\n");
        exit(1);
    }
    exit(0);
}

if ($operation === 'assert-membership') {
    $role = UserRole::tryFrom($argv[2] ?? '');
    $status = $argv[3] ?? '';
    if (! $role instanceof UserRole || ! in_array($status, ['active', 'removed'], true)) {
        fwrite(STDERR, "assert-membership requires a role and status\n");
        exit(1);
    }
    $actual = User::query()->where('email', $role->value.'@crate-c1.example.test')->value('managed_membership_status');
    if ($actual !== $status) {
        fwrite(STDERR, "managed membership assertion failed\n");
        exit(1);
    }
    exit(0);
}

fwrite(STDERR, "Unknown managed operation: {$operation}\n");
exit(1);
