<?php

/**
 * Stores gzip cache bodies outside the public web directory.
 *
 * Database rows only keep paths relative to the configured base directory.
 * Paths supplied by callers are always validated before filesystem access.
 */
final class ProxyCacheFileStore
{
    private $baseDir;
    private $writeEnabled;

    private function __construct($baseDir, $writeEnabled)
    {
        $this->baseDir = rtrim((string)$baseDir, DIRECTORY_SEPARATOR);
        $this->writeEnabled = (bool)$writeEnabled;
    }

    public static function fromConfig($config)
    {
        $defaultDir = dirname(__DIR__, 3) . '/runtime/cache-bodies';
        $baseDir = isset($config['cache_storage_dir'])
            ? (string)$config['cache_storage_dir']
            : $defaultDir;
        $writeEnabled = !array_key_exists('cache_file_storage_enabled', $config)
            || (bool)$config['cache_file_storage_enabled'];

        if (
            $baseDir === ''
            || substr($baseDir, 0, 1) !== DIRECTORY_SEPARATOR
            || rtrim($baseDir, DIRECTORY_SEPARATOR) === ''
        ) {
            throw new InvalidArgumentException('cache_storage_dir must be an absolute path');
        }

        return new self($baseDir, $writeEnabled);
    }

    public function isWriteEnabled()
    {
        return $this->writeEnabled;
    }

    public function getBaseDir()
    {
        return $this->baseDir;
    }

    /**
     * @return array{relative_path: string, stored_bytes: int, sha256: string}
     */
    public function writeGzip($cacheKey, $gzipBody)
    {
        if (!$this->writeEnabled) {
            throw new RuntimeException('Cache file writes are disabled');
        }

        $cacheKey = strtolower((string)$cacheKey);
        $gzipBody = (string)$gzipBody;

        if (!preg_match('/\A[a-f0-9]{64}\z/', $cacheKey)) {
            throw new InvalidArgumentException('Invalid cache key for cache file');
        }
        if (strlen($gzipBody) < 2 || substr($gzipBody, 0, 2) !== "\x1f\x8b") {
            throw new InvalidArgumentException('Cache file body is not gzip data');
        }

        $relativeDir = substr($cacheKey, 0, 2) . '/' . substr($cacheKey, 2, 2);
        $targetDir = $this->baseDir . '/' . $relativeDir;
        $this->ensureDirectory($targetDir);

        $generation = bin2hex(random_bytes(8));
        $relativePath = $relativeDir . '/' . $cacheKey . '.' . $generation . '.json.gz';
        $targetPath = $this->baseDir . '/' . $relativePath;
        $tempPath = tempnam($targetDir, '.cache-tmp-');
        if ($tempPath === false) {
            throw new RuntimeException('Failed to create temporary cache file');
        }

        try {
            $written = file_put_contents($tempPath, $gzipBody, LOCK_EX);
            if ($written === false || $written !== strlen($gzipBody)) {
                throw new RuntimeException('Failed to write complete cache file');
            }

            @chmod($tempPath, 0600);
            if (!@rename($tempPath, $targetPath)) {
                throw new RuntimeException('Failed to publish cache file atomically');
            }
            @chmod($targetPath, 0600);
        } catch (Exception $e) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }

        return array(
            'relative_path' => $relativePath,
            'stored_bytes' => strlen($gzipBody),
            'sha256' => hash('sha256', $gzipBody),
        );
    }

    /** @return string|null */
    public function read($relativePath)
    {
        $path = $this->resolvePath($relativePath);
        if ($path === null || !is_file($path)) {
            return null;
        }

        $body = @file_get_contents($path);
        return is_string($body) ? $body : null;
    }

    /**
     * Renames first and then unlinks. If unlink fails, the file is left in the
     * private trash directory where a later request can retry cleanup.
     */
    public function delete($relativePath)
    {
        $path = $this->resolvePath($relativePath);
        if ($path === null) {
            return false;
        }
        if (!is_file($path)) {
            return true;
        }

        $trashDir = $this->baseDir . '/.trash';
        $this->ensureDirectory($trashDir);
        $trashPath = $trashDir . '/' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.trash';

        if (!@rename($path, $trashPath)) {
            return false;
        }

        $deleted = @unlink($trashPath);
        $this->removeEmptyParentDirectories($path);
        return $deleted;
    }

    public function cleanupTrash($maxFiles)
    {
        $maxFiles = max(0, (int)$maxFiles);
        if ($maxFiles === 0) {
            return 0;
        }

        $trashDir = $this->baseDir . '/.trash';
        if (!is_dir($trashDir)) {
            return 0;
        }

        $names = @scandir($trashDir);
        if (!is_array($names)) {
            return 0;
        }

        $deleted = 0;
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($deleted >= $maxFiles) {
                break;
            }

            $path = $trashDir . '/' . $name;
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function resolvePath($relativePath)
    {
        $relativePath = (string)$relativePath;
        if (!preg_match(
            '/\A[a-f0-9]{2}\/[a-f0-9]{2}\/[a-f0-9]{64}\.[a-f0-9]{16}\.json\.gz\z/',
            $relativePath
        )) {
            return null;
        }

        return $this->baseDir . '/' . $relativePath;
    }

    private function ensureDirectory($path)
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create cache directory');
        }
        @chmod($path, 0700);
    }

    private function removeEmptyParentDirectories($filePath)
    {
        $level2 = dirname($filePath);
        $level1 = dirname($level2);

        if ($level2 !== $this->baseDir) {
            @rmdir($level2);
        }
        if ($level1 !== $this->baseDir) {
            @rmdir($level1);
        }
    }
}
