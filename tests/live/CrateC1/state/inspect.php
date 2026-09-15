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
if ($operation !== 'final') {
    fwrite(STDERR, "Unknown inspection operation: {$operation}\n");
    exit(1);
}

$secret = getenv('CRATE_C1_FIXTURE_SECRET');
$workDirectory = getenv('WORK_DIR');
if (! is_string($secret) || $secret === '' || ! is_string($workDirectory) || $workDirectory === '') {
    fwrite(STDERR, "inspection environment is incomplete\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workDirectory));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getSize() > 10_000_000) {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if (is_string($contents) && str_contains($contents, $secret)) {
        fwrite(STDERR, "fixture secret retained in {$file->getPathname()}\n");
        exit(1);
    }
}

echo "final inspection passed\n";
