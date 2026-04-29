<?php

declare(strict_types=1);

$configPath = dirname(__DIR__, 2) . '/config_overpass.php';

if (!is_file($configPath)) {
    jsonError(500, 'config_overpass.php not found');
}

$config = require $configPath;

if (!is_array($config)) {
    jsonError(500, 'config_overpass.php did not return array');
}

requireAdminAuth($config);

try {
    $pdo = createPdo($config['db']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $action = (string)($_GET['action'] ?? 'list');

        if ($action === 'list') {
            jsonResponse([
                'ok' => true,
                'items' => listCaches($pdo, $_GET),
            ]);
        }

        if ($action === 'summary') {
            jsonResponse([
                'ok' => true,
                'summary' => getSummary($pdo),
            ]);
        }

        jsonError(400, 'Unknown action');
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = [];

        if ($raw !== false && trim($raw) !== '') {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($body)) {
                jsonError(400, 'Invalid JSON');
            }
        }

        $action = (string)($body['action'] ?? '');

        if ($action === 'delete') {
            $keys = $body['keys'] ?? null;
            if (!is_array($keys) || $keys === []) {
                jsonError(400, 'keys is required');
            }

            $deleted = deleteCaches($pdo, $keys);

            jsonResponse([
                'ok' => true,
                'deleted' => $deleted,
            ]);
        }

        if ($action === 'delete_expired') {
            $deleted = deleteExpiredCaches($pdo);

            jsonResponse([
                'ok' => true,
                'deleted' => $deleted,
            ]);
        }

        jsonError(400, 'Unknown action');
    }

    jsonError(405, 'GET or POST only');
} catch (JsonException $e) {
    jsonError(400, 'Invalid JSON: ' . $e->getMessage());
} catch (PDOException $e) {
    jsonError(500, 'Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    jsonError(500, 'Server error: ' . $e->getMessage());
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

function listCaches(PDO $pdo, array $query): array
{
    $limit = isset($query['limit']) ? max(1, min(2000, (int)$query['limit'])) : 500;
    $includeExpired = isset($query['includeExpired']) && (string)$query['includeExpired'] === '1';

    $params = [];
    $where = [];

    if (!$includeExpired) {
        $where[] = 'expires_at > UTC_TIMESTAMP()';
    }

    $bbox = parseOptionalBBox($query);
    if ($bbox !== null) {
        [$south, $west, $north, $east] = $bbox;
        $where[] = 'normalized_east >= :west';
        $where[] = 'normalized_west <= :east';
        $where[] = 'normalized_north >= :south';
        $where[] = 'normalized_south <= :north';

        $params[':south'] = $south;
        $params[':west'] = $west;
        $params[':north'] = $north;
        $params[':east'] = $east;
    }

    $sql = 'SELECT
                cache_key,
                query_hash,
                normalized_bbox,
                normalized_south,
                normalized_west,
                normalized_north,
                normalized_east,
                bbox_area,
                content_type,
                status_code,
                OCTET_LENGTH(response_body) AS stored_bytes,
                body_encoding,
                DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at_utc,
                DATE_FORMAT(expires_at, "%Y-%m-%dT%H:%i:%sZ") AS expires_at_utc,
                (expires_at > UTC_TIMESTAMP()) AS is_active
            FROM overpass_cache';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY expires_at DESC, created_at DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        return [
            'cache_key' => (string)$row['cache_key'],
            'query_hash' => (string)$row['query_hash'],
            'normalized_bbox' => (string)$row['normalized_bbox'],
            'south' => (float)$row['normalized_south'],
            'west' => (float)$row['normalized_west'],
            'north' => (float)$row['normalized_north'],
            'east' => (float)$row['normalized_east'],
            'bbox_area' => (float)$row['bbox_area'],
            'content_type' => (string)$row['content_type'],
            'status_code' => (int)$row['status_code'],
            'stored_bytes' => (int)$row['stored_bytes'],
            'body_encoding' => (string)$row['body_encoding'],
            'created_at_utc' => (string)$row['created_at_utc'],
            'expires_at_utc' => (string)$row['expires_at_utc'],
            'is_active' => (bool)$row['is_active'],
        ];
    }, $rows);
}

function getSummary(PDO $pdo): array
{
    $sql = 'SELECT
                COUNT(*) AS total_count,
                SUM(expires_at > UTC_TIMESTAMP()) AS active_count,
                SUM(expires_at <= UTC_TIMESTAMP()) AS expired_count,
                COALESCE(SUM(OCTET_LENGTH(response_body)), 0) AS total_bytes
            FROM overpass_cache';

    $row = $pdo->query($sql)->fetch();

    return [
        'total_count' => (int)($row['total_count'] ?? 0),
        'active_count' => (int)($row['active_count'] ?? 0),
        'expired_count' => (int)($row['expired_count'] ?? 0),
        'total_bytes' => (int)($row['total_bytes'] ?? 0),
    ];
}

function deleteCaches(PDO $pdo, array $keys): int
{
    $keys = array_values(array_unique(array_filter(array_map(
        static fn($v) => is_string($v) ? trim($v) : '',
        $keys
    ))));

    if ($keys === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $sql = "DELETE FROM overpass_cache WHERE cache_key IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($keys);

    return $stmt->rowCount();
}

function deleteExpiredCaches(PDO $pdo): int
{
    $stmt = $pdo->prepare('DELETE FROM overpass_cache WHERE expires_at <= UTC_TIMESTAMP()');
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * @return array{0: float, 1: float, 2: float, 3: float}|null
 */
function parseOptionalBBox(array $query): ?array
{
    if (isset($query['bbox']) && is_string($query['bbox']) && trim($query['bbox']) !== '') {
        $parts = preg_split('/\s*,\s*/', trim($query['bbox']));
        if (is_array($parts) && count($parts) === 4) {
            return [
                (float)$parts[0],
                (float)$parts[1],
                (float)$parts[2],
                (float)$parts[3],
            ];
        }
    }

    $required = ['south', 'west', 'north', 'east'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $query)) {
            return null;
        }
    }

    return [
        (float)$query['south'],
        (float)$query['west'],
        (float)$query['north'],
        (float)$query['east'],
    ];
}

function requireAdminAuth(array $config): void
{
    $expectedUser = (string)($config['admin']['username'] ?? '');
    $expectedPass = (string)($config['admin']['password'] ?? '');

    if ($expectedUser === '' || $expectedPass === '') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(500);
        echo 'Admin auth is not configured';
        exit;
    }

    [$user, $pass] = getBasicAuthCredentials();

    if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
        header('WWW-Authenticate: Basic realm="Overpass Cache Admin"');
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(401);
        echo 'Authentication required';
        exit;
    }
}

/**
 * @return array{0: string, 1: string}
 */
function getBasicAuthCredentials(): array
{
    $user = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');

    if ($user !== '' || $pass !== '') {
        return [$user, $pass];
    }

    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded !== false) {
            $pos = strpos($decoded, ':');
            if ($pos !== false) {
                return [substr($decoded, 0, $pos), substr($decoded, $pos + 1)];
            }
        }
    }

    return ['', ''];
}

function jsonResponse(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(int $status, string $message): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode([
        'ok' => false,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
