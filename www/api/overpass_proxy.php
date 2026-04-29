<?php

declare(strict_types=1);

/**
 * Overpass API compatible proxy with MySQL cache
 *
 * Path:
 *   /www/api/overpass_proxy.php
 *
 * Rewrite example:
 *   /api/interpreter -> /www/api/overpass_proxy.php
 *
 * Supported request formats:
 *
 * 1) POST JSON
 * {
 *   "queryTemplate": "[out:json][timeout:25];nwr[\"amenity\"=\"cafe\"]({{bbox}});out center;",
 *   "bbox": [34.6998, 135.4921, 34.7087, 135.5038],
 *   "ttl": 60
 * }
 *
 * 2) GET query string
 * ?queryTemplate=...&bbox=...&ttl=60
 *
 * 3) GET/POST raw Overpass QL
 * ?data=[out:json][timeout:30][bbox:34.6,135.4,34.7,135.5];(...);out body;
 */

$configPath = dirname(__DIR__, 2) . '/config_overpass.php';

if (!is_file($configPath)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'config_overpass.php not found: ' . $configPath;
    exit;
}

$config = require $configPath;

if (!is_array($config)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'config_overpass.php did not return array';
    exit;
}

header('Vary: Origin');
header('X-Proxy-Version: 2026-04-29-route-by-bbox');

if (!empty($config['allow_origin'])) {
    header('Access-Control-Allow-Origin: ' . $config['allow_origin']);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'GET or POST only');
}

try {
    $pdo = createPdo($config['db']);

    maybeCleanupExpiredCache(
        $pdo,
        (int)($config['cleanup_probability_denominator'] ?? 200)
    );

    $input = readRequestInput((string)$_SERVER['REQUEST_METHOD'], $config);

    $queryTemplate = $input['queryTemplate'];
    $bbox = $input['bbox'];
    $ttl = $input['ttl'];
    $normalizationMode = $input['normalizationMode']; // template | raw_data
    $cacheable = $input['cacheable'];
    $rawQuery = $input['rawQuery'];

    $ttl = max(1, min($ttl, (int)($config['max_ttl'] ?? 600)));

    // bbox を抽出できない raw query は地域判定できないため、既定ルートへ流す
    // ここでは default_overpass_route = official_global を想定する。
    if (!$cacheable) {
        $route = chooseUpstreamForBBox(null, $config);

        $upstream = fetchOverpass(
            $route['url'],
            (string)$config['user_agent'],
            $rawQuery,
            (int)($config['curl_timeout_sec'] ?? 120)
        );

        header('X-Cache-Status: BYPASS');
        header('X-Cache-Reason: non_cacheable_query');
        header('X-Upstream-Route: ' . $route['name']);
        header('X-Upstream-Reason: ' . $route['reason']);
        header('X-Upstream-URL: ' . $route['url']);
        header('Content-Type: ' . $upstream['content_type']);
        http_response_code($upstream['status_code']);
        echo $upstream['body'];
        exit;
    }

    if ($queryTemplate === '' || strpos($queryTemplate, '{{bbox}}') === false) {
        jsonError(400, 'queryTemplate must contain {{bbox}}');
    }

    if ($bbox === null) {
        jsonError(400, 'bbox is required for cacheable query');
    }

    validateBbox($bbox);

    // 振り分けは、pad_ratio / round_step を適用する前の利用者指定 bbox で判定する。
    // 正規化後 bbox で判定すると、国内端の小さなクエリまで公式へ逃げやすくなるため。
    $route = chooseUpstreamForBBox($bbox, $config);

    $normalizedBBox = normalizeBBoxForMode($bbox, $normalizationMode, $config);
    $normalizedBBoxText = bboxToString($normalizedBBox);

    // 同じクエリでも、local_japan と official_global の結果を混ぜない。
    // query_hash も route 名込みにしておくことで、contains キャッシュの誤ヒットも避ける。
    $queryHash = hash('sha256', $route['name'] . "\n" . $queryTemplate);

    $cacheKey = hash('sha256', json_encode([
        'v' => 5,
        'route' => $route['name'],
        'queryTemplate' => $queryTemplate,
        'normalizedBBox' => $normalizedBBox,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cached = findCache($pdo, $cacheKey, $queryHash, $normalizedBBox);
    if ($cached !== null) {
        header('X-Cache-Status: HIT');
        header('X-Cache-Match: ' . $cached['match_type']); // exact | contains
        header('X-Normalized-BBox: ' . $normalizedBBoxText);
        header('X-Cache-Source-BBox: ' . $cached['normalized_bbox']);
        header('X-Normalization-Mode: ' . $normalizationMode);
        header('X-Upstream-Route: ' . $route['name']);
        header('X-Upstream-Reason: ' . $route['reason']);
        header('X-Upstream-URL: ' . $route['url']);
        header('Content-Type: ' . $cached['content_type']);
        http_response_code((int)$cached['status_code']);
        echo $cached['response_body'];
        exit;
    }

    $upstreamQuery = str_replace('{{bbox}}', $normalizedBBoxText, $queryTemplate);

    $upstream = fetchOverpass(
        $route['url'],
        (string)$config['user_agent'],
        $upstreamQuery,
        (int)($config['curl_timeout_sec'] ?? 120)
    );

    $cacheSkipReason = '';

    if ($upstream['status_code'] >= 200 && $upstream['status_code'] < 300) {
        $rawBody = $upstream['body'];
        $rawBodyBytes = strlen($rawBody);

        $maxCacheBodyBytes = (int)($config['max_cache_body_bytes'] ?? (4 * 1024 * 1024));
        $compressCache = (bool)($config['compress_cache'] ?? true);

        if ($rawBodyBytes <= $maxCacheBodyBytes) {
            $bodyEncoding = $compressCache ? 'gzip' : 'plain';
            $bodyToStore = encodeCacheBody($rawBody, $compressCache);
            $storedBytes = strlen($bodyToStore);

            if ($storedBytes <= $maxCacheBodyBytes) {
                saveCache(
                    $pdo,
                    $cacheKey,
                    $queryHash,
                    $normalizedBBox,
                    $normalizedBBoxText,
                    $bodyToStore,
                    $bodyEncoding,
                    $upstream['content_type'],
                    $upstream['status_code'],
                    $ttl
                );
            } else {
                $cacheSkipReason = 'compressed_body_too_large';
            }
        } else {
            $cacheSkipReason = 'raw_body_too_large';
        }
    }

    header('X-Cache-Status: MISS');
    header('X-Normalized-BBox: ' . $normalizedBBoxText);
    header('X-Normalization-Mode: ' . $normalizationMode);
    header('X-Upstream-Route: ' . $route['name']);
    header('X-Upstream-Reason: ' . $route['reason']);
    header('X-Upstream-URL: ' . $route['url']);
    if ($cacheSkipReason !== '') {
        header('X-Cache-Skip-Reason: ' . $cacheSkipReason);
    }
    header('Content-Type: ' . $upstream['content_type']);
    http_response_code($upstream['status_code']);
    echo $upstream['body'];
    exit;
} catch (JsonException $e) {
    jsonError(400, 'Invalid JSON: ' . $e->getMessage());
} catch (InvalidArgumentException $e) {
    jsonError(400, $e->getMessage());
} catch (PDOException $e) {
    jsonError(500, 'Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    jsonError(500, 'Server error: ' . $e->getMessage());
}

/**
 * @return array{
 *   queryTemplate: string,
 *   bbox: array{0: float, 1: float, 2: float, 3: float}|null,
 *   ttlMs: int,
 *   normalizationMode: string,
 *   cacheable: bool,
 *   rawQuery: string
 * }
 */
function readRequestInput(string $method, array $config): array
{
    $defaultTtl = (int)($config['default_ttl'] ?? 60);

    if ($method === 'POST') {
        $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            jsonError(400, 'Empty request body');
        }

        if (str_contains($contentType, 'application/json')) {
            $req = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($req)) {
                jsonError(400, 'Invalid JSON');
            }

            return buildInputFromAssoc($req, $defaultTtl);
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $req);

            if (is_array($req) && $req !== []) {
                return buildInputFromAssoc($req, $defaultTtl);
            }
        }

        return buildInputFromAssoc([
            'data' => $raw,
        ], $defaultTtl);
    }

    if ($method === 'GET') {
        return buildInputFromAssoc($_GET, $defaultTtl);
    }

    throw new RuntimeException('Unsupported request method');
}

/**
 * @return array{
 *   queryTemplate: string,
 *   bbox: array{0: float, 1: float, 2: float, 3: float}|null,
 *   ttl: int,
 *   normalizationMode: string,
 *   cacheable: bool,
 *   rawQuery: string
 * }
 */
function buildInputFromAssoc(array $source, int $defaultTtl): array
{
    $ttl = isset($source['ttl']) ? (int)$source['ttl'] : $defaultTtl;

    if (isset($source['queryTemplate'])) {
        $queryTemplate = normalizeTemplate((string)$source['queryTemplate']);
        $bbox = parseBBoxFromMixed($source['bbox'] ?? null, $source);

        return [
            'queryTemplate' => $queryTemplate,
            'bbox' => $bbox,
            'ttl' => $ttl,
            'normalizationMode' => 'template',
            'cacheable' => true,
            'rawQuery' => '',
        ];
    }

    if (isset($source['data'])) {
        $data = normalizeTemplate((string)$source['data']);
        $parsed = tryExtractBBoxAndTemplateFromData($data);

        if ($parsed === null) {
            return [
                'queryTemplate' => '',
                'bbox' => null,
                'ttl' => $ttl,
                'normalizationMode' => 'raw_data',
                'cacheable' => false,
                'rawQuery' => $data,
            ];
        }

        [$bbox, $queryTemplate] = $parsed;

        return [
            'queryTemplate' => $queryTemplate,
            'bbox' => $bbox,
            'ttl' => $ttl,
            'normalizationMode' => 'raw_data',
            'cacheable' => true,
            'rawQuery' => $data,
        ];
    }

    throw new InvalidArgumentException('queryTemplate or data is required');
}

/**
 * @return array{
 *   0: array{0: float, 1: float, 2: float, 3: float},
 *   1: string
 * }|null
 */
function tryExtractBBoxAndTemplateFromData(string $data): ?array
{
    $number = '[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?';

    $globalPattern = '/\[bbox\s*:\s*(' . $number . ')\s*,\s*(' . $number . ')\s*,\s*(' . $number . ')\s*,\s*(' . $number . ')\s*\]/i';

    if (preg_match($globalPattern, $data, $m)) {
        $bbox = [
            (float)$m[1],
            (float)$m[2],
            (float)$m[3],
            (float)$m[4],
        ];

        $template = preg_replace($globalPattern, '[bbox:{{bbox}}]', $data, 1, $count);

        if (is_string($template) && $count === 1) {
            return [$bbox, $template];
        }
    }

    $inlinePattern = '/\b(node|way|relation|rel|nwr)\b((?:\s*\[[^\]]*\])*)\s*\(\s*(' . $number . ')\s*,\s*(' . $number . ')\s*,\s*(' . $number . ')\s*,\s*(' . $number . ')\s*\)/i';

    if (!preg_match_all($inlinePattern, $data, $matches, PREG_SET_ORDER)) {
        return null;
    }

    $bboxes = [];
    foreach ($matches as $m) {
        $bboxes[] = [
            (float)$m[3],
            (float)$m[4],
            (float)$m[5],
            (float)$m[6],
        ];
    }

    $south = $bboxes[0][0];
    $west  = $bboxes[0][1];
    $north = $bboxes[0][2];
    $east  = $bboxes[0][3];

    foreach ($bboxes as $b) {
        $south = min($south, $b[0]);
        $west  = min($west,  $b[1]);
        $north = max($north, $b[2]);
        $east  = max($east,  $b[3]);
    }

    $bbox = [$south, $west, $north, $east];

    $template = preg_replace(
        $inlinePattern,
        '$1$2({{bbox}})',
        $data
    );

    if (!is_string($template)) {
        return null;
    }

    return [$bbox, $template];
}

/**
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function parseBBoxFromMixed(mixed $bboxValue, array $source): array
{
    if (is_array($bboxValue) && count($bboxValue) === 4) {
        return [
            (float)$bboxValue[0],
            (float)$bboxValue[1],
            (float)$bboxValue[2],
            (float)$bboxValue[3],
        ];
    }

    if (is_string($bboxValue) && trim($bboxValue) !== '') {
        $parts = preg_split('/\s*,\s*/', trim($bboxValue));
        if (is_array($parts) && count($parts) === 4) {
            return [
                (float)$parts[0],
                (float)$parts[1],
                (float)$parts[2],
                (float)$parts[3],
            ];
        }
        throw new InvalidArgumentException('bbox string must be "south,west,north,east"');
    }

    $required = ['south', 'west', 'north', 'east'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $source)) {
            throw new InvalidArgumentException(
                'bbox must be [south, west, north, east], "south,west,north,east", or south/west/north/east params'
            );
        }
    }

    return [
        (float)$source['south'],
        (float)$source['west'],
        (float)$source['north'],
        (float)$source['east'],
    ];
}

function createPdo(array $db): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['dbname'],
        $db['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function normalizeTemplate(string $s): string
{
    return str_replace("\r\n", "\n", trim($s));
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $bbox
 */
function validateBbox(array $bbox): void
{
    [$south, $west, $north, $east] = $bbox;

    foreach ($bbox as $v) {
        if (!is_finite($v)) {
            throw new InvalidArgumentException('bbox contains non-finite number');
        }
    }

    if ($south < -90 || $south > 90 || $north < -90 || $north > 90) {
        throw new InvalidArgumentException('latitude out of range');
    }

    if ($west < -180 || $west > 180 || $east < -180 || $east > 180) {
        throw new InvalidArgumentException('longitude out of range');
    }

    if ($south >= $north || $west >= $east) {
        throw new InvalidArgumentException('bbox order must be [south, west, north, east]');
    }
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $bbox
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function normalizeBBoxForMode(array $bbox, string $mode, array $config): array
{
    if ($mode === 'raw_data') {
        $preserve = (bool)($config['raw_data_preserve_bbox'] ?? true);
        if ($preserve) {
            return [
                round($bbox[0], 6),
                round($bbox[1], 6),
                round($bbox[2], 6),
                round($bbox[3], 6),
            ];
        }
    }

    return normalizeBBox(
        $bbox,
        (float)($config['pad_ratio'] ?? 0.5),
        (float)($config['round_step'] ?? 0.005)
    );
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $bbox
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function normalizeBBox(array $bbox, float $padRatio, float $step): array
{
    [$south, $west, $north, $east] = $bbox;

    if ($step <= 0) {
        throw new InvalidArgumentException('round_step must be > 0');
    }

    if ($padRatio < 0) {
        throw new InvalidArgumentException('pad_ratio must be >= 0');
    }

    $latSpan = $north - $south;
    $lonSpan = $east - $west;

    $padLat = $latSpan * $padRatio;
    $padLon = $lonSpan * $padRatio;

    $south -= $padLat;
    $west  -= $padLon;
    $north += $padLat;
    $east  += $padLon;

    $south = roundDown($south, $step);
    $west  = roundDown($west, $step);
    $north = roundUp($north, $step);
    $east  = roundUp($east, $step);

    $south = max(-90.0, $south);
    $north = min(90.0, $north);
    $west  = max(-180.0, $west);
    $east  = min(180.0, $east);

    return [
        round($south, 6),
        round($west, 6),
        round($north, 6),
        round($east, 6),
    ];
}

function roundDown(float $value, float $step): float
{
    return floor($value / $step) * $step;
}

function roundUp(float $value, float $step): float
{
    return ceil($value / $step) * $step;
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $bbox
 */
function bboxToString(array $bbox): string
{
    return implode(',', array_map(
        static fn($v) => rtrim(rtrim(sprintf('%.6F', (float)$v), '0'), '.'),
        $bbox
    ));
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float}|null $bbox
 * @return array{name: string, url: string, reason: string}
 */
function chooseUpstreamForBBox(?array $bbox, array $config): array
{
    $routes = $config['overpass_routes'] ?? null;

    // 旧 config でも一応動くようにしておく。
    if (!is_array($routes) || $routes === []) {
        $legacyUrl = (string)($config['overpass_url'] ?? '');
        if ($legacyUrl === '') {
            throw new RuntimeException('overpass_routes or overpass_url is required');
        }

        return [
            'name' => 'legacy',
            'url' => $legacyUrl,
            'reason' => 'legacy_overpass_url',
        ];
    }

    if ($bbox !== null) {
        foreach ($routes as $name => $route) {
            if (!is_string($name) || !is_array($route) || empty($route['url'])) {
                continue;
            }

            foreach (routeBboxes($route) as $routeBBox) {
                if (bboxContains($routeBBox, $bbox)) {
                    return [
                        'name' => $name,
                        'url' => (string)$route['url'],
                        'reason' => 'bbox_contained',
                    ];
                }
            }
        }
    }

    $defaultName = (string)($config['default_overpass_route'] ?? '');
    if ($defaultName !== '' && isset($routes[$defaultName]) && is_array($routes[$defaultName]) && !empty($routes[$defaultName]['url'])) {
        return [
            'name' => $defaultName,
            'url' => (string)$routes[$defaultName]['url'],
            'reason' => $bbox === null ? 'no_bbox_default' : 'bbox_not_contained_default',
        ];
    }

    foreach ($routes as $name => $route) {
        if (is_string($name) && is_array($route) && !empty($route['url'])) {
            return [
                'name' => $name,
                'url' => (string)$route['url'],
                'reason' => 'first_available_route',
            ];
        }
    }

    throw new RuntimeException('No usable overpass route configured');
}

/**
 * @return array<int, array{0: float, 1: float, 2: float, 3: float}>
 */
function routeBboxes(array $route): array
{
    $result = [];

    if (isset($route['bbox'])) {
        $result[] = parseRouteBbox($route['bbox']);
    }

    if (isset($route['bboxes']) && is_array($route['bboxes'])) {
        foreach ($route['bboxes'] as $bbox) {
            $result[] = parseRouteBbox($bbox);
        }
    }

    return $result;
}

/**
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function parseRouteBbox(mixed $bbox): array
{
    if (!is_array($bbox) || count($bbox) !== 4) {
        throw new InvalidArgumentException('route bbox must be [south, west, north, east]');
    }

    $parsed = [
        (float)$bbox[0],
        (float)$bbox[1],
        (float)$bbox[2],
        (float)$bbox[3],
    ];

    validateBbox($parsed);
    return $parsed;
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $outer
 * @param array{0: float, 1: float, 2: float, 3: float} $inner
 */
function bboxContains(array $outer, array $inner): bool
{
    $epsilon = 1e-9;

    return $outer[0] <= $inner[0] + $epsilon
        && $outer[1] <= $inner[1] + $epsilon
        && $outer[2] + $epsilon >= $inner[2]
        && $outer[3] + $epsilon >= $inner[3];
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $requestedBBox
 * @return array{
 *   response_body: string,
 *   content_type: string,
 *   status_code: int,
 *   match_type: string,
 *   normalized_bbox: string
 * }|null
 */
function findCache(PDO $pdo, string $cacheKey, string $queryHash, array $requestedBBox): ?array
{
    $exact = findExactCache($pdo, $cacheKey);
    if ($exact !== null) {
        return $exact;
    }

    return findContainingCache($pdo, $queryHash, $requestedBBox);
}

/**
 * @return array{
 *   response_body: string,
 *   content_type: string,
 *   status_code: int,
 *   match_type: string,
 *   normalized_bbox: string
 * }|null
 */
function findExactCache(PDO $pdo, string $cacheKey): ?array
{
    $sql = 'SELECT normalized_bbox, response_body, body_encoding, content_type, status_code
            FROM overpass_cache
            WHERE cache_key = :cache_key
              AND expires_at > UTC_TIMESTAMP()
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':cache_key' => $cacheKey,
    ]);

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $body = decodeCacheBody((string)$row['response_body'], (string)$row['body_encoding']);
    if ($body === null) {
        return null;
    }

    return [
        'response_body' => $body,
        'content_type' => (string)$row['content_type'],
        'status_code' => (int)$row['status_code'],
        'match_type' => 'exact',
        'normalized_bbox' => (string)$row['normalized_bbox'],
    ];
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $requestedBBox
 * @return array{
 *   response_body: string,
 *   content_type: string,
 *   status_code: int,
 *   match_type: string,
 *   normalized_bbox: string
 * }|null
 */
function findContainingCache(PDO $pdo, string $queryHash, array $requestedBBox): ?array
{
    [$south, $west, $north, $east] = $requestedBBox;

    $sql = 'SELECT normalized_bbox, response_body, body_encoding, content_type, status_code
            FROM overpass_cache
            WHERE query_hash = :query_hash
              AND expires_at > UTC_TIMESTAMP()
              AND normalized_south <= :south
              AND normalized_west  <= :west
              AND normalized_north >= :north
              AND normalized_east  >= :east
            ORDER BY bbox_area ASC, created_at DESC
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':query_hash', $queryHash, PDO::PARAM_STR);
    $stmt->bindValue(':south', $south);
    $stmt->bindValue(':west', $west);
    $stmt->bindValue(':north', $north);
    $stmt->bindValue(':east', $east);
    $stmt->execute();

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $body = decodeCacheBody((string)$row['response_body'], (string)$row['body_encoding']);
    if ($body === null) {
        return null;
    }

    return [
        'response_body' => $body,
        'content_type' => (string)$row['content_type'],
        'status_code' => (int)$row['status_code'],
        'match_type' => 'contains',
        'normalized_bbox' => (string)$row['normalized_bbox'],
    ];
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $normalizedBBox
 */
function saveCache(
    PDO $pdo,
    string $cacheKey,
    string $queryHash,
    array $normalizedBBox,
    string $normalizedBBoxText,
    string $responseBody,
    string $bodyEncoding,
    string $contentType,
    int $statusCode,
    int $ttlSeconds
): void {
    [$south, $west, $north, $east] = $normalizedBBox;
    $bboxArea = calcBboxArea($normalizedBBox);

    $sql = 'INSERT INTO overpass_cache
            (
              cache_key,
              query_hash,
              normalized_bbox,
              normalized_south,
              normalized_west,
              normalized_north,
              normalized_east,
              bbox_area,
              response_body,
              body_encoding,
              content_type,
              status_code,
              created_at,
              expires_at
            )
            VALUES
            (
              :cache_key,
              :query_hash,
              :normalized_bbox,
              :normalized_south,
              :normalized_west,
              :normalized_north,
              :normalized_east,
              :bbox_area,
              :response_body,
              :body_encoding,
              :content_type,
              :status_code,
              UTC_TIMESTAMP(),
              DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl_seconds SECOND)
            )
            ON DUPLICATE KEY UPDATE
              query_hash = VALUES(query_hash),
              normalized_bbox = VALUES(normalized_bbox),
              normalized_south = VALUES(normalized_south),
              normalized_west = VALUES(normalized_west),
              normalized_north = VALUES(normalized_north),
              normalized_east = VALUES(normalized_east),
              bbox_area = VALUES(bbox_area),
              response_body = VALUES(response_body),
              body_encoding = VALUES(body_encoding),
              content_type = VALUES(content_type),
              status_code = VALUES(status_code),
              created_at = UTC_TIMESTAMP(),
              expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl_seconds SECOND)';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':cache_key', $cacheKey, PDO::PARAM_STR);
    $stmt->bindValue(':query_hash', $queryHash, PDO::PARAM_STR);
    $stmt->bindValue(':normalized_bbox', $normalizedBBoxText, PDO::PARAM_STR);
    $stmt->bindValue(':normalized_south', $south);
    $stmt->bindValue(':normalized_west', $west);
    $stmt->bindValue(':normalized_north', $north);
    $stmt->bindValue(':normalized_east', $east);
    $stmt->bindValue(':bbox_area', $bboxArea);
    $stmt->bindValue(':response_body', $responseBody, PDO::PARAM_LOB);
    $stmt->bindValue(':body_encoding', $bodyEncoding, PDO::PARAM_STR);
    $stmt->bindValue(':content_type', $contentType, PDO::PARAM_STR);
    $stmt->bindValue(':status_code', $statusCode, PDO::PARAM_INT);
    $stmt->bindValue(':ttl_seconds', $ttlSeconds, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $bbox
 */
function calcBboxArea(array $bbox): float
{
    [$south, $west, $north, $east] = $bbox;
    return max(0.0, ($north - $south) * ($east - $west));
}

function maybeCleanupExpiredCache(PDO $pdo, int $denominator): void
{
    if ($denominator < 1) {
        return;
    }

    if (random_int(1, $denominator) !== 1) {
        return;
    }

    $sql = 'DELETE FROM overpass_cache
            WHERE expires_at < UTC_TIMESTAMP()
            LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
}

function fetchOverpass(
    string $url,
    string $userAgent,
    string $query,
    int $timeoutSec
): array {
    $postData = http_build_query(['data' => $query], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
            'User-Agent: ' . $userAgent,
        ],
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    $rawHeader = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    if ($contentType === '') {
        $contentType = detectContentTypeFromHeaders($rawHeader) ?: 'text/plain; charset=utf-8';
    }

    unset($ch);

    return [
        'status_code' => $statusCode,
        'content_type' => $contentType,
        'body' => $body,
    ];
}

function encodeCacheBody(string $body, bool $compress): string
{
    if (!$compress) {
        return $body;
    }

    $gz = gzencode($body, 6);
    if ($gz === false) {
        throw new RuntimeException('gzencode failed');
    }

    return $gz;
}

function decodeCacheBody(string $storedBody, string $bodyEncoding): ?string
{
    if ($bodyEncoding === 'plain') {
        return $storedBody;
    }

    if ($bodyEncoding === 'gzip') {
        $decoded = @gzdecode($storedBody);
        if ($decoded === false) {
            return null;
        }
        return $decoded;
    }

    return null;
}

function detectContentTypeFromHeaders(string $rawHeader): ?string
{
    $lines = preg_split("/\r\n|\n|\r/", $rawHeader);
    if (!is_array($lines)) {
        return null;
    }

    foreach ($lines as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            return trim(substr($line, strlen('Content-Type:')));
        }
    }

    return null;
}

function jsonError(int $status, string $message): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode([
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
