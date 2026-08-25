<?php

declare(strict_types=1);

final class ProxyRequestLimitException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}

final class ProxyRequestPolicy
{
    /**
     * @param array<mixed> $values
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public static function parseBboxCoordinates(array $values): array
    {
        if (!array_is_list($values) || count($values) !== 4) {
            throw new InvalidArgumentException('bbox must contain exactly four ordered coordinates');
        }

        $labels = ['south', 'west', 'north', 'east'];
        $parsed = [];

        foreach ($values as $index => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }

            if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
                throw new InvalidArgumentException($labels[$index] . ' must be numeric');
            }

            $number = (float)$value;
            if (!is_finite($number)) {
                throw new InvalidArgumentException($labels[$index] . ' must be finite');
            }
            $parsed[] = $number;
        }

        /** @var array{0: float, 1: float, 2: float, 3: float} $parsed */
        return $parsed;
    }

    public static function assertQuerySize(string $query, array $config): void
    {
        $maxBytes = max(1, (int)($config['max_query_bytes'] ?? 65536));
        if (strlen($query) > $maxBytes) {
            throw new ProxyRequestLimitException(
                'Overpass query exceeds the configured size limit',
                413
            );
        }
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    public static function assertBboxSize(array $bbox, array $config): void
    {
        [$south, $west, $north, $east] = $bbox;
        $latSpan = $north - $south;
        $lonSpan = $east - $west;
        $area = $latSpan * $lonSpan;

        $maxLatSpan = (float)($config['max_bbox_lat_span_degrees'] ?? 10.0);
        $maxLonSpan = (float)($config['max_bbox_lon_span_degrees'] ?? 10.0);
        $maxArea = (float)($config['max_bbox_area_degrees2'] ?? 25.0);

        if ($maxLatSpan > 0 && $latSpan > $maxLatSpan) {
            throw new ProxyRequestLimitException(
                'bbox latitude span exceeds the configured limit',
                400
            );
        }

        if ($maxLonSpan > 0 && $lonSpan > $maxLonSpan) {
            throw new ProxyRequestLimitException(
                'bbox longitude span exceeds the configured limit',
                400
            );
        }

        if ($maxArea > 0 && $area > $maxArea) {
            throw new ProxyRequestLimitException(
                'bbox area exceeds the configured limit',
                400
            );
        }
    }

    public static function requestBodyLimit(array $config): int
    {
        return max(1, (int)($config['max_request_body_bytes'] ?? 65536));
    }
}

/**
 * Holds one non-blocking flock slot for the lifetime of a proxy request.
 * REMOTE_ADDR is intentionally used instead of a spoofable forwarding header.
 */
final class ProxyClientConcurrencyLease
{
    /** @var resource|null */
    private $handle;

    private function __construct($handle = null)
    {
        $this->handle = $handle;
    }

    public static function acquire(array $config, array $server): self
    {
        $maxConcurrent = (int)($config['max_concurrent_requests_per_client'] ?? 2);
        if ($maxConcurrent < 1) {
            return new self();
        }

        $clientAddress = trim((string)($server['REMOTE_ADDR'] ?? 'unknown'));
        if ($clientAddress === '') {
            $clientAddress = 'unknown';
        }

        $configuredDir = trim((string)($config['concurrency_lock_dir'] ?? ''));
        $lockDir = $configuredDir !== ''
            ? $configuredDir
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'overpass-proxy-client-locks';

        self::ensureLockDirectory($lockDir);

        $clientHash = hash('sha256', $clientAddress);
        $openFailures = 0;

        for ($slot = 0; $slot < $maxConcurrent; $slot++) {
            $path = $lockDir . DIRECTORY_SEPARATOR . $clientHash . '-' . $slot . '.lock';
            $handle = @fopen($path, 'c+');
            if ($handle === false) {
                $openFailures++;
                continue;
            }

            @chmod($path, 0600);

            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                ftruncate($handle, 0);
                fwrite($handle, sprintf("pid=%d acquired=%s\n", getmypid(), gmdate('c')));
                fflush($handle);
                return new self($handle);
            }

            fclose($handle);
        }

        if ($openFailures === $maxConcurrent) {
            throw new RuntimeException('Unable to open client concurrency lock files');
        }

        $retryAfter = max(1, (int)($config['concurrency_retry_after_sec'] ?? 5));
        throw new ProxyRequestLimitException(
            'Too many concurrent requests from this client',
            429,
            $retryAfter
        );
    }

    private static function ensureLockDirectory(string $lockDir): void
    {
        if (is_link($lockDir)) {
            throw new RuntimeException('Client concurrency lock directory must not be a symbolic link');
        }

        if (is_dir($lockDir)) {
            @chmod($lockDir, 0700);
            return;
        }

        if (!@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Unable to create client concurrency lock directory');
        }
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
