<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

$appDirectory = getenv('APP_DIR');
if (! is_string($appDirectory) || $appDirectory === '') {
    fwrite(STDERR, "APP_DIR is required\n");
    exit(1);
}

require $appDirectory.'/vendor/autoload.php';
$app = require $appDirectory.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = $argv[1] ?? '';
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

echo "seeded substrate\n";
