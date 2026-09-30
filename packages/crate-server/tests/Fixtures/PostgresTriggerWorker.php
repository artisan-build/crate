<?php

declare(strict_types=1);

use ArtisanBuild\CrateServer\Actions\CreateOrReplayMcpBuild;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$idempotencyKey = $argv[1] ?? '';
$fingerprint = $argv[2] ?? '';
$barrier = getenv('CRATE_PG_BARRIER');
$deadline = microtime(true) + 10;

while (is_string($barrier) && ! file_exists($barrier)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the PostgreSQL concurrency barrier.');
    }

    usleep(10000);
}

$database = new Manager;
$database->addConnection([
    'driver' => 'pgsql',
    'host' => getenv('CRATE_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('CRATE_DB_PORT') ?: '5432',
    'database' => getenv('CRATE_DB_DATABASE') ?: 'crate_mcp_test',
    'username' => getenv('CRATE_DB_USERNAME') ?: 'root',
    'password' => getenv('CRATE_DB_PASSWORD') ?: '',
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
], 'crate');
Model::setConnectionResolver($database->getDatabaseManager());

$result = (new CreateOrReplayMcpBuild)->handle(
    'postgres-process',
    $idempotencyKey,
    $fingerprint,
    null,
);
$build = $result['build'];
$conflict = ! hash_equals((string) $build->request_fingerprint, $fingerprint);

fwrite(STDOUT, json_encode([
    'build_id' => (int) $build->getKey(),
    'duplicate' => ! $result['created'] && ! $conflict,
    'conflict' => $conflict,
], JSON_THROW_ON_ERROR));
