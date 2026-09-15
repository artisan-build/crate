<?php

declare(strict_types=1);

$statePath = getenv('CRATE_C1_AUTHORITY_STATE');
$logPath = getenv('CRATE_C1_AUTHORITY_LOG');
$controlToken = getenv('CRATE_C1_CONTROL_TOKEN');

if (! is_string($statePath) || $statePath === '' || ! is_string($logPath) || $logPath === '') {
    http_response_code(500);
    echo "stub paths are not configured\n";

    return;
}

/** @return array<string, mixed> */
function readState(string $path): array
{
    if (! is_file($path)) {
        return ['responses' => [], 'requests' => []];
    }

    $contents = file_get_contents($path);
    $decoded = is_string($contents) ? json_decode($contents, true) : null;

    return is_array($decoded) ? $decoded : ['responses' => [], 'requests' => []];
}

/** @param array<string, mixed> $state */
function writeState(string $path, array $state): void
{
    $temporary = $path.'.tmp';
    file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    rename($temporary, $path);
}

/** @param array<string, mixed> $record */
function appendLog(string $path, array $record): void
{
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);

    return;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$safeHeaders = [];
foreach ($headers as $name => $value) {
    if (in_array(strtolower((string) $name), ['authorization', 'cookie', 'x-crate-c1-control'], true)) {
        $safeHeaders[$name] = '[redacted]';
    } else {
        $safeHeaders[$name] = $value;
    }
}

$body = file_get_contents('php://input');
appendLog($logPath, [
    'method' => $method,
    'path' => $path,
    'headers' => $safeHeaders,
    'body_sha256' => hash('sha256', is_string($body) ? $body : ''),
]);

if (str_starts_with($path, '/__control/')) {
    $provided = $headers['X-Crate-C1-Control'] ?? $headers['x-crate-c1-control'] ?? null;
    if (! is_string($controlToken) || ! is_string($provided) || ! hash_equals($controlToken, $provided)) {
        http_response_code(403);

        return;
    }

    $payload = is_string($body) ? json_decode($body, true) : null;
    if ($method === 'POST' && $path === '/__control/reset') {
        writeState($statePath, ['responses' => [], 'requests' => []]);
        http_response_code(204);

        return;
    }

    if ($method === 'POST' && $path === '/__control/enqueue' && is_array($payload)) {
        $state = readState($statePath);
        $state['responses'][] = $payload;
        writeState($statePath, $state);
        http_response_code(204);

        return;
    }

    http_response_code(404);

    return;
}

$state = readState($statePath);
$responses = is_array($state['responses'] ?? null) ? $state['responses'] : [];
$response = array_shift($responses);
$state['responses'] = $responses;
$state['requests'][] = ['method' => $method, 'path' => $path];
writeState($statePath, $state);

if (! is_array($response)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'no scripted response'], JSON_THROW_ON_ERROR);

    return;
}

$delayMs = filter_var($response['delay_ms'] ?? 0, FILTER_VALIDATE_INT);
if (is_int($delayMs) && $delayMs > 0) {
    usleep($delayMs * 1000);
}

http_response_code((int) ($response['status'] ?? 200));
header('Content-Type: application/json');
echo json_encode($response['body'] ?? [], JSON_THROW_ON_ERROR);
