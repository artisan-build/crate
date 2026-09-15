<?php

declare(strict_types=1);

use Aws\S3\S3Client;

$appDirectory = getenv('APP_DIR');
if (! is_string($appDirectory) || $appDirectory === '') {
    fwrite(STDERR, "APP_DIR is required\n");
    exit(1);
}

require $appDirectory.'/vendor/autoload.php';

$bucket = getenv('CRATE_C1_S3_BUCKET');
$endpoint = getenv('AWS_ENDPOINT');
$accessKey = getenv('AWS_ACCESS_KEY_ID');
$secretKey = getenv('AWS_SECRET_ACCESS_KEY');
if (! is_string($bucket) || ! is_string($endpoint) || ! is_string($accessKey) || ! is_string($secretKey)) {
    fwrite(STDERR, "S3 environment is incomplete\n");
    exit(1);
}

$client = new S3Client([
    'version' => 'latest',
    'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
    'endpoint' => $endpoint,
    'use_path_style_endpoint' => true,
    'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
]);

$operation = $argv[1] ?? '';
if ($operation === 'create-bucket') {
    $client->createBucket(['Bucket' => $bucket]);
    echo "created bucket\n";
    exit(0);
}

if ($operation === 'delete-bucket') {
    do {
        $objects = $client->listObjectsV2(['Bucket' => $bucket]);
        foreach ($objects['Contents'] ?? [] as $object) {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
        }
    } while (($objects['IsTruncated'] ?? false) === true);

    $client->deleteBucket(['Bucket' => $bucket]);
    exit(0);
}

fwrite(STDERR, "Unknown storage operation: {$operation}\n");
exit(1);
