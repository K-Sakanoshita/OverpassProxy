<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/www/api/lib/CacheFileStore.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nexpected=' . var_export($expected, true) . '\nactual=' . var_export($actual, true)
        );
    }
}

function removeTestDirectory(string $root): void
{
    if (!is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($root);
}

$testRoot = sys_get_temp_dir() . '/overpass-proxy-cache-file-test-' . bin2hex(random_bytes(6));
$cacheKey = hash('sha256', 'cache-file-store-test');
$plainBody = json_encode([
    'version' => 0.6,
    'elements' => [
        ['type' => 'node', 'id' => 1, 'lat' => 34.8, 'lon' => 135.6],
    ],
], JSON_UNESCAPED_SLASHES);

if (!is_string($plainBody)) {
    throw new RuntimeException('Failed to prepare test JSON');
}

$gzipBody = gzencode($plainBody, 6);
if (!is_string($gzipBody)) {
    throw new RuntimeException('Failed to prepare test gzip');
}

try {
    $rootRejected = false;
    try {
        ProxyCacheFileStore::fromConfig([
            'cache_storage_dir' => DIRECTORY_SEPARATOR,
        ]);
    } catch (InvalidArgumentException $e) {
        $rootRejected = true;
    }
    assertSameValue(true, $rootRejected, 'filesystem root rejection');

    $store = ProxyCacheFileStore::fromConfig([
        'cache_file_storage_enabled' => true,
        'cache_storage_dir' => $testRoot,
    ]);

    $metadata = $store->writeGzip($cacheKey, $gzipBody);
    assertSameValue(strlen($gzipBody), $metadata['stored_bytes'], 'stored byte count');
    assertSameValue(hash('sha256', $gzipBody), $metadata['sha256'], 'stored sha256');
    assertSameValue(
        substr($cacheKey, 0, 2) . '/' . substr($cacheKey, 2, 2) . '/',
        substr($metadata['relative_path'], 0, 6),
        'cache key directory sharding'
    );
    assertSameValue(
        0600,
        fileperms($testRoot . '/' . $metadata['relative_path']) & 0777,
        'cache file permissions'
    );

    $storedBody = $store->read($metadata['relative_path']);
    assertSameValue($gzipBody, $storedBody, 'file read');
    assertSameValue($plainBody, gzdecode((string)$storedBody), 'gzip round trip');
    assertSameValue(null, $store->read('../../config_overpass.php'), 'path traversal rejection');

    $disabledStore = ProxyCacheFileStore::fromConfig([
        'cache_file_storage_enabled' => false,
        'cache_storage_dir' => $testRoot,
    ]);
    assertSameValue($gzipBody, $disabledStore->read($metadata['relative_path']), 'read while writes disabled');

    $writeRejected = false;
    try {
        $disabledStore->writeGzip($cacheKey, $gzipBody);
    } catch (RuntimeException $e) {
        $writeRejected = true;
    }
    assertSameValue(true, $writeRejected, 'disabled write rejection');

    assertSameValue(true, $store->delete($metadata['relative_path']), 'file delete');
    assertSameValue(null, $store->read($metadata['relative_path']), 'deleted file is absent');

    echo "cache_file_store_test: PASS\n";
} finally {
    removeTestDirectory($testRoot);
}
