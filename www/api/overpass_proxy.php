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

$supportPaths = [
    __DIR__ . '/lib/RequestGuard.php',
    __DIR__ . '/lib/BboxGrid.php',
    __DIR__ . '/lib/RawQueryBbox.php',
];
foreach ($supportPaths as $supportPath) {
    if (!is_file($supportPath)) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo 'Required proxy support module is not available';
        exit;
    }
    require_once $supportPath;
}

applyRuntimeLimits($config);

header('Vary: Origin');
header('X-Proxy-Version: 2026-08-23-raw-grid-v1');

if (!empty($config['allow_origin'])) {
    header('Access-Control-Allow-Origin: ' . $config['allow_origin']);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'GET or POST only');
}

try {
    // REMOTE_ADDR ごとに、要求の受付から応答完了まで1スロットを保持する。
    // リースは exit / fatal error を含むリクエスト終了時に自動解放される。
    $clientConcurrencyLease = ProxyClientConcurrencyLease::acquire($config, $_SERVER);

    // 入力の構文が明らかに壊れている場合は、DBにも上流Overpassにも行かずに即400を返す。
    // 例: `...;>;o` のような明らかな構文ミスは、上流へ投げずに即400を返す。
    $input = readRequestInput((string)$_SERVER['REQUEST_METHOD'], $config);

    $queryTemplate = $input['queryTemplate'];
    $bbox = $input['bbox'];
    $ttl = $input['ttl'];
    $normalizationMode = $input['normalizationMode']; // template | raw_data
    $cacheable = $input['cacheable'];
    $rawQuery = $input['rawQuery'];

    $preflightQuery = $rawQuery !== '' ? $rawQuery : $queryTemplate;
    ProxyRequestPolicy::assertQuerySize($preflightQuery, $config);

    $preflightError = validateOverpassQueryPreflight($preflightQuery);
    if ($preflightError !== null) {
        invalidOverpassQuery($preflightError);
    }

    $ttl = max(1, min($ttl, (int)($config['max_ttl'] ?? 600)));

    // bbox を抽出できない raw query は地域判定できないため、既定ルートへ流す
    // ここでは default_overpass_route = official_global を想定する。
    if (!$cacheable) {
        $route = chooseUpstreamForBBox(null, $config);

        header('X-Cache-Status: BYPASS');
        header('X-Cache-Reason: non_cacheable_query');
        header('X-BBox-Policy: raw_no_bbox_passthrough');
        header('X-Upstream-Query-Mode: raw_passthrough');
        header('X-Upstream-Route: ' . $route['name']);
        header('X-Upstream-Reason: ' . $route['reason']);
        header('X-Upstream-URL: ' . $route['url']);
        header('X-Stream-Mode: direct');

        streamOverpassToClient(
            $route['url'],
            (string)$config['user_agent'],
            $rawQuery,
            (int)($config['curl_timeout_sec'] ?? 180),
            $config
        );
        exit;
    }

    if ($queryTemplate === '' || strpos($queryTemplate, '{{bbox}}') === false) {
        jsonError(400, 'queryTemplate must contain {{bbox}}');
    }

    if ($bbox === null) {
        jsonError(400, 'bbox is required for cacheable query');
    }

    validateBbox($bbox);
    ProxyRequestPolicy::assertBboxSize($bbox, $config);

    $rawDataRequest = $normalizationMode === 'raw_data' && $rawQuery !== '';
    $rawDataGrid = $rawDataRequest
        && $queryTemplate !== ''
        && (bool)($config['raw_bbox_grid_enabled'] ?? false);
    $rawDataPassthrough = $rawDataRequest
        && !$rawDataGrid
        && (bool)($config['raw_data_passthrough_upstream'] ?? true);

    $bboxGridStep = null;
    if ($rawDataGrid) {
        $grid = ProxyBboxGrid::align($bbox, $config);
        $normalizedBBox = $grid['bbox'];
        $bboxGridStep = $grid['step'];
        $normalizedBBoxText = bboxToString($normalizedBBox);
        $queryIdentity = $queryTemplate;
        $bboxPolicy = (string)($config['raw_bbox_grid_policy_version'] ?? 'raw_grid_v1');
        $upstreamQueryMode = 'raw_grid';
    } elseif ($rawDataPassthrough) {
        $normalizedBBox = preserveBBox($bbox);
        $normalizedBBoxText = bboxToString($normalizedBBox);
        $queryIdentity = $rawQuery;
        $bboxPolicy = 'raw_exact_v1';
        $upstreamQueryMode = 'raw_passthrough';
    } else {
        $normalizedBBox = normalizeBBoxForMode($bbox, $normalizationMode, $config);
        $normalizedBBoxText = bboxToString($normalizedBBox);
        $queryIdentity = $queryTemplate;
        $bboxPolicy = $rawDataRequest ? 'raw_normalized_v1' : 'template_pad_round_v1';
        $upstreamQueryMode = $rawDataRequest ? 'raw_normalized' : 'normalized_template';
    }

    // pad_ratio / round_step 適用後の、実際に上流へ送る範囲も同じ上限内に収める。
    ProxyRequestPolicy::assertBboxSize($normalizedBBox, $config);

    // rawグリッドは実際に上流へ送る範囲でルートを判定し、ローカル収録範囲外への拡張を避ける。
    // 従来モードとtemplateモードは、既存互換性のため利用者指定bboxで判定する。
    $route = chooseUpstreamForBBox($rawDataGrid ? $normalizedBBox : $bbox, $config);

    header('X-BBox-Policy: ' . $bboxPolicy);
    if ($bboxGridStep !== null) {
        header('X-BBox-Grid-Step: ' . formatFloatHeader($bboxGridStep));
    }

    // 同じクエリでも、local_japan と official_global の結果を混ぜない。
    // raw passthrough 時は rawQuery 自体を identity に入れて、bbox入りクエリ同士の誤ヒットを避ける。
    $queryHash = hash('sha256', $route['name'] . "\n" . $normalizationMode . "\n" . $bboxPolicy . "\n" . $queryIdentity);

    $cacheKey = hash('sha256', json_encode([
        'v' => 7,
        'route' => $route['name'],
        'normalizationMode' => $normalizationMode,
        'bboxPolicy' => $bboxPolicy,
        'bboxGridStep' => $bboxGridStep,
        'rawDataGrid' => $rawDataGrid,
        'rawDataPassthrough' => $rawDataPassthrough,
        'queryIdentity' => $queryIdentity,
        'normalizedBBox' => $normalizedBBox,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // DB障害は上流プロキシまで停止させない。キャッシュだけをBYPASSして処理を継続する。
    $pdo = null;
    $cached = null;
    try {
        $pdo = createPdo($config['db']);
        maybeCleanupExpiredCache(
            $pdo,
            (int)($config['cleanup_probability_denominator'] ?? 200)
        );
        $cached = findCache(
            $pdo,
            $cacheKey,
            $queryHash,
            $normalizedBBox,
            (bool)($config['allow_containing_cache_match'] ?? false)
        );
    } catch (PDOException $e) {
        error_log('Overpass proxy cache DB unavailable: ' . $e->getMessage());
        $pdo = null;
        header('X-Cache-Status: BYPASS');
        header('X-Cache-Reason: db_unavailable');
    }

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

    $upstreamQuery = $rawDataPassthrough
        ? $rawQuery
        : str_replace('{{bbox}}', $normalizedBBoxText, $queryTemplate);

    // タイムアウト時にも、どの上流へどのモードで投げようとしたか分かるように先に出す。
    header('X-Upstream-Query-Mode: ' . $upstreamQueryMode);
    header('X-Upstream-Route: ' . $route['name']);
    header('X-Upstream-Reason: ' . $route['reason']);
    header('X-Upstream-URL: ' . $route['url']);

    // raw dataはグリッド化の有無にかかわらず、大きなbboxだけ直接ストリーミングする。
    // 小さいraw dataは通常経路で取得し、DBキャッシュ対象にする。
    $streamDecision = shouldDirectStreamRawData($rawDataRequest, $normalizedBBox, $config);
    if ($streamDecision['stream']) {
        header('X-Cache-Status: ' . ($pdo === null ? 'BYPASS' : 'MISS'));
        header('X-Normalized-BBox: ' . $normalizedBBoxText);
        header('X-Normalization-Mode: ' . $normalizationMode);
        header('X-Cache-Skip-Reason: ' . $streamDecision['reason']);
        header('X-Stream-Mode: direct');
        header('X-Stream-BBox-Area: ' . formatFloatHeader($streamDecision['area']));
        header('X-Stream-BBox-Area-Threshold: ' . formatFloatHeader($streamDecision['threshold']));

        streamOverpassToClient(
            $route['url'],
            (string)$config['user_agent'],
            $upstreamQuery,
            (int)($config['curl_timeout_sec'] ?? 180),
            $config
        );
        exit;
    }

    $upstream = fetchOverpass(
        $route['url'],
        (string)$config['user_agent'],
        $upstreamQuery,
        (int)($config['curl_timeout_sec'] ?? 120),
        $config
    );

    $maxCacheBodyBytes = (int)($config['max_cache_body_bytes'] ?? (4 * 1024 * 1024));
    $rawBodyForCache = getUpstreamBodyForCache($upstream, $maxCacheBodyBytes);

    if ($pdo === null) {
        $cacheSkipReason = 'db_unavailable';
    } elseif ($rawBodyForCache === null) {
        $cacheSkipReason = ((int)($upstream['body_bytes'] ?? 0) > $maxCacheBodyBytes)
            ? 'raw_body_too_large'
            : 'body_not_available_for_cache';
    } else {
        $cacheDecision = shouldCacheOverpassResponse([
            'status_code' => (int)$upstream['status_code'],
            'content_type' => (string)$upstream['content_type'],
            'body' => $rawBodyForCache,
        ]);
        $cacheSkipReason = $cacheDecision['reason'];

        if ($cacheDecision['cacheable']) {
            $rawBodyBytes = strlen($rawBodyForCache);
            $compressCache = (bool)($config['compress_cache'] ?? true);

            if ($rawBodyBytes <= $maxCacheBodyBytes) {
                $bodyEncoding = $compressCache ? 'gzip' : 'plain';
                $bodyToStore = encodeCacheBody($rawBodyForCache, $compressCache);
                $storedBytes = strlen($bodyToStore);

                if ($storedBytes <= $maxCacheBodyBytes) {
                    try {
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
                        $cacheSkipReason = '';
                    } catch (PDOException $e) {
                        error_log('Overpass proxy cache save failed: ' . $e->getMessage());
                        $cacheSkipReason = 'db_save_failed';
                    }
                } else {
                    $cacheSkipReason = 'compressed_body_too_large';
                }
            } else {
                $cacheSkipReason = 'raw_body_too_large';
            }
        }
    }

    header('X-Cache-Status: ' . ($pdo === null ? 'BYPASS' : 'MISS'));
    header('X-Normalized-BBox: ' . $normalizedBBoxText);
    header('X-Normalization-Mode: ' . $normalizationMode);
    if ($cacheSkipReason !== '') {
        header('X-Cache-Skip-Reason: ' . $cacheSkipReason);
    }
    header('X-Upstream-Body-Bytes: ' . (string)($upstream['body_bytes'] ?? strlen((string)($upstream['body'] ?? ''))));
    header('Content-Type: ' . $upstream['content_type']);
    http_response_code($upstream['status_code']);
    outputUpstreamBody($upstream);
    cleanupUpstreamBody($upstream);
    exit;
} catch (ProxyRequestLimitException $e) {
    if ($e->retryAfterSeconds() !== null) {
        header('Retry-After: ' . $e->retryAfterSeconds());
    }
    jsonError($e->httpStatus(), $e->getMessage());
} catch (JsonException $e) {
    jsonError(400, 'Invalid JSON: ' . $e->getMessage());
} catch (InvalidArgumentException $e) {
    jsonError(400, $e->getMessage());
} catch (PDOException $e) {
    error_log('Overpass proxy database error: ' . $e->getMessage());
    jsonError(500, 'Database operation failed');
} catch (Throwable $e) {
    error_log('Overpass proxy server error: ' . $e->getMessage());
    jsonError(500, 'Internal server error');
}


function applyRuntimeLimits(array $config): void
{
    $memoryLimit = (string)($config['php_memory_limit'] ?? '512M');
    $maxExecutionSec = (int)($config['php_max_execution_time_sec'] ?? 240);

    if ($memoryLimit !== '') {
        @ini_set('memory_limit', $memoryLimit);
    }

    if ($maxExecutionSec > 0) {
        @ini_set('max_execution_time', (string)$maxExecutionSec);
        @set_time_limit($maxExecutionSec);
    }

    // レスポンスをPHP側で圧縮・バッファリングすると、巨大JSONの直接転送と相性が悪い。
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
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
        $maxBodyBytes = ProxyRequestPolicy::requestBodyLimit($config);
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null;

        if ($contentLength !== null && $contentLength > $maxBodyBytes) {
            throw new ProxyRequestLimitException(
                'Request body exceeds the configured size limit',
                413
            );
        }

        // Content-Length がないリクエストでも、上限+1バイトまでしかPHPメモリへ読み込まない。
        $raw = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);

        if ($raw === false || trim($raw) === '') {
            jsonError(400, 'Empty request body');
        }

        if (strlen($raw) > $maxBodyBytes) {
            throw new ProxyRequestLimitException(
                'Request body exceeds the configured size limit',
                413
            );
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
    return ProxyRawQueryBbox::extract($data);
}

/**
 * @return array{0: float, 1: float, 2: float, 3: float}
 */
function parseBBoxFromMixed(mixed $bboxValue, array $source): array
{
    if (is_array($bboxValue) && count($bboxValue) === 4) {
        return ProxyRequestPolicy::parseBboxCoordinates($bboxValue);
    }

    if (is_string($bboxValue) && trim($bboxValue) !== '') {
        $parts = preg_split('/\s*,\s*/', trim($bboxValue));
        if (is_array($parts) && count($parts) === 4) {
            return ProxyRequestPolicy::parseBboxCoordinates($parts);
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

    return ProxyRequestPolicy::parseBboxCoordinates([
        $source['south'],
        $source['west'],
        $source['north'],
        $source['east'],
    ]);
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
function preserveBBox(array $bbox): array
{
    return [
        round($bbox[0], 6),
        round($bbox[1], 6),
        round($bbox[2], 6),
        round($bbox[3], 6),
    ];
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

    if (!is_array($routes) || $routes === []) {
        throw new RuntimeException('overpass_routes is required');
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
    if ($defaultName === '') {
        throw new RuntimeException('default_overpass_route is required');
    }

    if (!isset($routes[$defaultName]) || !is_array($routes[$defaultName]) || empty($routes[$defaultName]['url'])) {
        throw new RuntimeException('default_overpass_route is not configured: ' . $defaultName);
    }

    return [
        'name' => $defaultName,
        'url' => (string)$routes[$defaultName]['url'],
        'reason' => $bbox === null ? 'no_bbox_default' : 'bbox_not_contained_default',
    ];
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
function findCache(
    PDO $pdo,
    string $cacheKey,
    string $queryHash,
    array $requestedBBox,
    bool $allowContainingMatch = false
): ?array
{
    $exact = findExactCache($pdo, $cacheKey);
    if ($exact !== null) {
        return $exact;
    }

    if (!$allowContainingMatch) {
        return null;
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
    $sql = 'SELECT cache_key, normalized_bbox, response_body, body_encoding, content_type, status_code
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
        deleteCacheByKey($pdo, (string)$row['cache_key']);
        return null;
    }

    $cacheValidation = shouldCacheOverpassResponse([
        'status_code' => (int)$row['status_code'],
        'content_type' => (string)$row['content_type'],
        'body' => $body,
    ]);
    if (!$cacheValidation['cacheable']) {
        deleteCacheByKey($pdo, (string)$row['cache_key']);
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

    $sql = 'SELECT cache_key, normalized_bbox, response_body, body_encoding, content_type, status_code
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
        deleteCacheByKey($pdo, (string)$row['cache_key']);
        return null;
    }

    $cacheValidation = shouldCacheOverpassResponse([
        'status_code' => (int)$row['status_code'],
        'content_type' => (string)$row['content_type'],
        'body' => $body,
    ]);
    if (!$cacheValidation['cacheable']) {
        deleteCacheByKey($pdo, (string)$row['cache_key']);
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

function deleteCacheByKey(PDO $pdo, string $cacheKey): void
{
    if ($cacheKey === '') {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM overpass_cache WHERE cache_key = :cache_key LIMIT 1');
    $stmt->execute([
        ':cache_key' => $cacheKey,
    ]);
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

/**
 * raw_data のMISS時に直接ストリーミングするかを判定する。
 *
 * 既定は large_only。
 * - 小さいbbox: 通常取得してキャッシュ対象にする
 * - 大きいbbox: 100MB級レスポンス対策として直接ストリーミングし、キャッシュしない
 *
 * 設定例:
 *   'stream_raw_data_passthrough' => true,
 *   'stream_raw_data_passthrough_mode' => 'large_only', // large_only | always | never
 *   'direct_stream_bbox_area_threshold' => 0.02,
 *
 * リクエストごとの強制指定:
 *   &direct_stream=1 または &stream=1 または &no_cache=1
 *
 * @param array{0: float, 1: float, 2: float, 3: float} $normalizedBBox
 * @return array{stream: bool, reason: string, area: float, threshold: float}
 */
function shouldDirectStreamRawData(bool $rawDataRequest, array $normalizedBBox, array $config): array
{
    $area = calcBboxArea($normalizedBBox);
    $threshold = (float)($config['direct_stream_bbox_area_threshold']
        ?? $config['raw_data_stream_bbox_area_threshold']
        ?? 0.02);

    if (!$rawDataRequest) {
        return [
            'stream' => false,
            'reason' => 'not_raw_data_request',
            'area' => $area,
            'threshold' => $threshold,
        ];
    }

    $enabled = (bool)($config['stream_raw_data_passthrough'] ?? true);
    if (!$enabled) {
        return [
            'stream' => false,
            'reason' => 'stream_raw_data_passthrough_disabled',
            'area' => $area,
            'threshold' => $threshold,
        ];
    }

    if (requestFlagEnabled('direct_stream') || requestFlagEnabled('stream') || requestFlagEnabled('no_cache')) {
        return [
            'stream' => true,
            'reason' => 'raw_data_stream_forced_by_request',
            'area' => $area,
            'threshold' => $threshold,
        ];
    }

    $mode = strtolower((string)($config['stream_raw_data_passthrough_mode'] ?? 'large_only'));

    if (in_array($mode, ['always', 'direct', 'all', 'true', '1'], true)) {
        return [
            'stream' => true,
            'reason' => 'raw_data_stream_always',
            'area' => $area,
            'threshold' => $threshold,
        ];
    }

    if (in_array($mode, ['never', 'off', 'none', 'false', '0'], true)) {
        return [
            'stream' => false,
            'reason' => 'raw_data_stream_never',
            'area' => $area,
            'threshold' => $threshold,
        ];
    }

    if ($threshold > 0 && $area >= $threshold) {
        return [
            'stream' => true,
            'reason' => 'raw_data_stream_large_bbox',
            'area' => $area,
            'threshold' => $threshold,
        ];
    }

    return [
        'stream' => false,
        'reason' => 'raw_data_cacheable_small_bbox',
        'area' => $area,
        'threshold' => $threshold,
    ];
}

function requestFlagEnabled(string $key): bool
{
    if (!isset($_GET[$key])) {
        return false;
    }

    $value = strtolower(trim((string)$_GET[$key]));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function formatFloatHeader(float $value): string
{
    return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
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

function validateOverpassQueryPreflight(string $query): ?string
{
    $q = trim(stripOverpassComments($query));

    if ($q === '') {
        return 'Empty Overpass query';
    }

    // 再現ケース: `out body;>;o`
    // `>` は再帰指定として有効だが、その後の裸の `o` は Overpass QL の有効な命令ではない。
    // 上流Overpassへ送ると環境によっては応答待ちになり得るので、ここで即400にする。
    if (preg_match('/(?:^|;)\s*o\s*;?\s*$/i', $q)) {
        return 'Unknown or unsupported Overpass statement "o"';
    }

    return null;
}

function stripOverpassComments(string $query): string
{
    $query = preg_replace('/\/\*.*?\*\//s', '', $query);
    if (!is_string($query)) {
        return '';
    }

    $query = preg_replace('/\/\/.*$/m', '', $query);
    if (!is_string($query)) {
        return '';
    }

    return $query;
}

function invalidOverpassQuery(string $detail): never
{
    header('X-Cache-Status: BYPASS');
    header('X-Cache-Reason: invalid_query_preflight');
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid Overpass query',
        'detail' => $detail,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function streamOverpassToClient(
    string $url,
    string $userAgent,
    string $query,
    int $timeoutSec,
    array $config = []
): void {
    $method = strtoupper((string)($config['upstream_request_method'] ?? 'GET'));
    $getMaxBytes = (int)($config['upstream_get_max_query_bytes'] ?? 16000);

    if ($method === 'AUTO') {
        $method = strlen($query) <= $getMaxBytes ? 'GET' : 'POST';
    }

    if ($method === 'GET' && strlen($query) > $getMaxBytes) {
        $method = 'POST';
    }

    if ($method !== 'GET' && $method !== 'POST') {
        $method = 'GET';
    }

    $requestUrl = $url;
    $postData = null;

    if ($method === 'GET') {
        $requestUrl = appendQueryString($url, http_build_query(['data' => $query], '', '&', PHP_QUERY_RFC3986));
    } else {
        $postData = http_build_query(['data' => $query], '', '&', PHP_QUERY_RFC3986);
    }

    $connectTimeoutSec = (int)($config['curl_connect_timeout_sec'] ?? 10);
    $noBodyTimeoutSec = (int)($config['curl_no_body_timeout_sec'] ?? $timeoutSec);
    $ipResolve = strtolower((string)($config['curl_ipresolve'] ?? 'v6'));
    $httpVersion = strtolower((string)($config['curl_http_version'] ?? '1.1'));

    $statusCode = 200;
    $contentType = 'application/json; charset=utf-8';
    $rawHeader = '';
    $headersSentByStream = false;
    $bodyBytes = 0;

    $ch = curl_init($requestUrl);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL');
    }

    $sendStreamHeaders = static function () use (&$headersSentByStream, &$statusCode, &$contentType): void {
        if ($headersSentByStream) {
            return;
        }

        if (!headers_sent()) {
            http_response_code($statusCode >= 100 ? $statusCode : 200);
            header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/json; charset=utf-8'));
            header('X-Stream-Started: 1');
            header('X-Accel-Buffering: no');
        }

        closeOutputBuffersForStreaming();
        $headersSentByStream = true;
    };

    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$rawHeader, &$statusCode, &$contentType): int {
            if (preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d+)/i', $headerLine, $m)) {
                $rawHeader = $headerLine;
                $statusCode = (int)$m[1];
            } else {
                $rawHeader .= $headerLine;
                if (stripos($headerLine, 'Content-Type:') === 0) {
                    $detected = trim(substr($headerLine, strlen('Content-Type:')));
                    if ($detected !== '') {
                        $contentType = $detected;
                    }
                }
            }
            return strlen($headerLine);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bodyBytes, $sendStreamHeaders): int {
            $len = strlen($chunk);
            $bodyBytes += $len;

            $sendStreamHeaders();
            echo $chunk;
            flush();

            return $len;
        },
    ];

    if ($ipResolve === 'v4' && defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    } elseif ($ipResolve === 'v6' && defined('CURL_IPRESOLVE_V6')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
    }

    if ($httpVersion === '1.1' && defined('CURL_HTTP_VERSION_1_1')) {
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    } elseif ($httpVersion === '2' && defined('CURL_HTTP_VERSION_2TLS')) {
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    }

    if ($noBodyTimeoutSec > 0) {
        $options[CURLOPT_LOW_SPEED_LIMIT] = 1;
        $options[CURLOPT_LOW_SPEED_TIME] = $noBodyTimeoutSec;
    }

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $postData;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);

    $ok = curl_exec($ch);

    if (!$headersSentByStream) {
        $infoStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($infoStatus > 0) {
            $statusCode = $infoStatus;
        }
        $infoContentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($infoContentType !== '') {
            $contentType = $infoContentType;
        } elseif ($contentType === '') {
            $contentType = detectContentTypeFromHeaders($rawHeader) ?: 'application/json; charset=utf-8';
        }
    }

    if ($ok === false) {
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $diag = [
            'errno=' . $errno,
            'method=' . $method,
            'host=' . (string)(parse_url($url, PHP_URL_HOST) ?: ''),
            'primary_ip=' . (string)($info['primary_ip'] ?? ''),
            'local_ip=' . (string)($info['local_ip'] ?? ''),
            'total_time=' . (string)($info['total_time'] ?? ''),
            'body_bytes=' . $bodyBytes,
        ];

        if ($headersSentByStream) {
            error_log('Overpass proxy streaming cURL error: ' . $err . ' [' . implode(' ', $diag) . ']');
            return;
        }

        throw new RuntimeException('cURL error: ' . $err . ' [' . implode(' ', $diag) . ']');
    }

    curl_close($ch);

    if (!$headersSentByStream) {
        if (!headers_sent()) {
            http_response_code($statusCode >= 100 ? $statusCode : 204);
            header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/json; charset=utf-8'));
            header('X-Stream-Started: 0');
            header('X-Upstream-Body-Bytes: ' . $bodyBytes);
        }
    }

    flush();
}

function closeOutputBuffersForStreaming(): void
{
    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) {
            break;
        }
    }
    flush();
}

function fetchOverpass(
    string $url,
    string $userAgent,
    string $query,
    int $timeoutSec,
    array $config = []
): array {
    $method = strtoupper((string)($config['upstream_request_method'] ?? 'GET'));
    $getMaxBytes = (int)($config['upstream_get_max_query_bytes'] ?? 16000);

    // 既定はGET。長すぎる場合のみPOSTへフォールバックする。
    if ($method === 'AUTO') {
        $method = strlen($query) <= $getMaxBytes ? 'GET' : 'POST';
    }

    if ($method === 'GET' && strlen($query) > $getMaxBytes) {
        $method = 'POST';
    }

    if ($method !== 'GET' && $method !== 'POST') {
        $method = 'GET';
    }

    $connectTimeoutSec = (int)($config['curl_connect_timeout_sec'] ?? 10);
    $noBodyTimeoutSec = (int)($config['curl_no_body_timeout_sec'] ?? $timeoutSec);
    $ipResolve = strtolower((string)($config['curl_ipresolve'] ?? 'v6'));
    $httpVersion = strtolower((string)($config['curl_http_version'] ?? '1.1'));

    // 小さい応答だけメモリに載せ、一定以上は一時ファイルに退避する。
    // CURLOPT_RETURNTRANSFERで巨大JSONを丸ごと文字列化すると、PHPのmemory_limitでfatal 500になり得る。
    $memoryBodyLimit = max(1, (int)($config['max_response_body_memory_bytes'] ?? (8 * 1024 * 1024)));
    $bodyBytes = 0;
    $bodyInMemory = '';
    $bodyFile = null;
    $bodyFileHandle = null;
    $rawHeader = '';

    $requestUrl = $url;
    $postData = null;

    if ($method === 'GET') {
        $requestUrl = appendQueryString($url, http_build_query(['data' => $query], '', '&', PHP_QUERY_RFC3986));
    } else {
        $postData = http_build_query(['data' => $query], '', '&', PHP_QUERY_RFC3986);
    }

    $ch = curl_init($requestUrl);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL');
    }

    $openTempBodyFile = static function () use (&$bodyFile, &$bodyFileHandle, &$bodyInMemory): void {
        if (is_resource($bodyFileHandle)) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'overpass_proxy_body_');
        if ($tmp === false) {
            throw new RuntimeException('Failed to create temporary body file');
        }

        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to open temporary body file');
        }

        $bodyFile = $tmp;
        $bodyFileHandle = $fh;

        if ($bodyInMemory !== '') {
            fwrite($bodyFileHandle, $bodyInMemory);
            $bodyInMemory = '';
        }
    };

    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_BUFFERSIZE => 65536,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$rawHeader): int {
            // リダイレクト等で複数ヘッダブロックが来るため、空行の後にHTTP行が来たら最後のブロックとして扱う。
            if (preg_match('/^HTTP\/\d+(?:\.\d+)?\s+/i', $headerLine)) {
                $rawHeader = $headerLine;
            } else {
                $rawHeader .= $headerLine;
            }
            return strlen($headerLine);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bodyBytes, &$bodyInMemory, &$bodyFileHandle, $memoryBodyLimit, $openTempBodyFile): int {
            $len = strlen($chunk);
            $bodyBytes += $len;

            if (is_resource($bodyFileHandle)) {
                fwrite($bodyFileHandle, $chunk);
                return $len;
            }

            if (($bodyBytes) <= $memoryBodyLimit) {
                $bodyInMemory .= $chunk;
                return $len;
            }

            $openTempBodyFile();
            fwrite($bodyFileHandle, $chunk);
            return $len;
        },
    ];

    if ($ipResolve === 'v4' && defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    } elseif ($ipResolve === 'v6' && defined('CURL_IPRESOLVE_V6')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
    }

    if ($httpVersion === '1.1' && defined('CURL_HTTP_VERSION_1_1')) {
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    } elseif ($httpVersion === '2' && defined('CURL_HTTP_VERSION_2TLS')) {
        $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    }

    if ($noBodyTimeoutSec > 0) {
        $options[CURLOPT_LOW_SPEED_LIMIT] = 1;
        $options[CURLOPT_LOW_SPEED_TIME] = $noBodyTimeoutSec;
    }

    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $postData;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded; charset=utf-8';
    } else {
        $options[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $options);

    $ok = curl_exec($ch);

    if (is_resource($bodyFileHandle)) {
        fflush($bodyFileHandle);
        fclose($bodyFileHandle);
        $bodyFileHandle = null;
    }

    if ($ok === false) {
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (is_string($bodyFile) && $bodyFile !== '') {
            @unlink($bodyFile);
        }

        $diag = [
            'errno=' . $errno,
            'method=' . $method,
            'host=' . (string)(parse_url($url, PHP_URL_HOST) ?: ''),
            'primary_ip=' . (string)($info['primary_ip'] ?? ''),
            'local_ip=' . (string)($info['local_ip'] ?? ''),
            'total_time=' . (string)($info['total_time'] ?? ''),
        ];

        throw new RuntimeException('cURL error: ' . $err . ' [' . implode(' ', $diag) . ']');
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    if ($contentType === '') {
        $contentType = detectContentTypeFromHeaders($rawHeader) ?: 'text/plain; charset=utf-8';
    }

    curl_close($ch);

    return [
        'status_code' => $statusCode,
        'content_type' => $contentType,
        'body' => $bodyInMemory,
        'body_file' => $bodyFile,
        'body_bytes' => $bodyBytes,
        'method' => $method,
    ];
}

function appendQueryString(string $url, string $queryString): string
{
    if ($queryString === '') {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . $queryString;
}


/**
 * キャッシュ判定に使えるサイズの場合だけ本文文字列を返す。
 * 大きいレスポンスはクライアントへは返すが、メモリ節約のためキャッシュしない。
 *
 * @param array{body?: string, body_file?: ?string, body_bytes?: int} $upstream
 */
function getUpstreamBodyForCache(array $upstream, int $maxCacheBodyBytes): ?string
{
    $bodyBytes = (int)($upstream['body_bytes'] ?? strlen((string)($upstream['body'] ?? '')));
    if ($bodyBytes > $maxCacheBodyBytes) {
        return null;
    }

    if (isset($upstream['body']) && is_string($upstream['body']) && $upstream['body'] !== '') {
        return $upstream['body'];
    }

    $bodyFile = $upstream['body_file'] ?? null;
    if (is_string($bodyFile) && $bodyFile !== '' && is_file($bodyFile)) {
        $body = file_get_contents($bodyFile);
        return is_string($body) ? $body : null;
    }

    return isset($upstream['body']) && is_string($upstream['body']) ? $upstream['body'] : null;
}

/**
 * @param array{body?: string, body_file?: ?string} $upstream
 */
function outputUpstreamBody(array $upstream): void
{
    $bodyFile = $upstream['body_file'] ?? null;
    if (is_string($bodyFile) && $bodyFile !== '' && is_file($bodyFile)) {
        readfile($bodyFile);
        return;
    }

    echo (string)($upstream['body'] ?? '');
}

/**
 * @param array{body_file?: ?string} $upstream
 */
function cleanupUpstreamBody(array $upstream): void
{
    $bodyFile = $upstream['body_file'] ?? null;
    if (is_string($bodyFile) && $bodyFile !== '' && is_file($bodyFile)) {
        @unlink($bodyFile);
    }
}

/**
 * キャッシュする条件は単純にする。
 * - HTTP 2xx
 * - 本文が JSON としてパースできる
 *
 * Overpass の HTML/XML エラー応答は、上流が 200 を返したとしてもキャッシュしない。
 * @param array{status_code: int, content_type: string, body: string} $upstream
 * @return array{cacheable: bool, reason: string}
 */
function shouldCacheOverpassResponse(array $upstream): array
{
    $statusCode = (int)$upstream['status_code'];
    $body = (string)$upstream['body'];

    if ($statusCode < 200 || $statusCode >= 300) {
        return ['cacheable' => false, 'reason' => 'upstream_http_' . $statusCode];
    }

    if (!isJsonString($body)) {
        return ['cacheable' => false, 'reason' => 'not_json_body'];
    }

    return ['cacheable' => true, 'reason' => ''];
}

function isJsonString(string $s): bool
{
    if (trim($s) === '') {
        return false;
    }

    try {
        json_decode($s, true, 512, JSON_THROW_ON_ERROR);
        return json_last_error() === JSON_ERROR_NONE;
    } catch (JsonException) {
        return false;
    }
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
