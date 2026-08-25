<?php
/*
 * Overpass Cache Admin API
 * Conservative PHP syntax version.
 * This file intentionally avoids newer PHP syntax so that shared hosting does
 * not answer with a silent HTTP 500 just because PHP decided to cosplay as a trapdoor.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

function cache_admin_json_flags()
{
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) {
        $flags |= JSON_UNESCAPED_UNICODE;
    }
    if (defined('JSON_UNESCAPED_SLASHES')) {
        $flags |= JSON_UNESCAPED_SLASHES;
    }
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

function cache_admin_fatal_handler()
{
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR);
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    echo json_encode(array(
        'ok' => false,
        'error' => 'PHP fatal error: ' . (isset($error['message']) ? (string)$error['message'] : 'unknown error'),
        'file' => isset($error['file']) ? basename((string)$error['file']) : '',
        'line' => isset($error['line']) ? (int)$error['line'] : 0,
    ), cache_admin_json_flags());
}

register_shutdown_function('cache_admin_fatal_handler');

function json_response($data)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, cache_admin_json_flags());
    exit;
}

function json_error($status, $message, $extra)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code((int)$status);
    }

    $data = array(
        'ok' => false,
        'error' => (string)$message,
    );

    if (is_array($extra)) {
        foreach ($extra as $k => $v) {
            $data[$k] = $v;
        }
    }

    echo json_encode($data, cache_admin_json_flags());
    exit;
}

function load_config()
{
    $configPath = dirname(dirname(__DIR__)) . '/config_overpass.php';

    if (!is_file($configPath)) {
        json_error(500, 'config_overpass.php not found', array(
            'config_path' => $configPath,
            'api_dir' => __DIR__,
        ));
    }

    $config = require $configPath;

    if (!is_array($config)) {
        json_error(500, 'config_overpass.php did not return array', array(
            'config_path' => $configPath,
        ));
    }

    return $config;
}

function require_admin_auth($config)
{
    $expectedUser = '';
    $expectedPass = '';

    if (isset($config['admin']) && is_array($config['admin'])) {
        $expectedUser = isset($config['admin']['username']) ? (string)$config['admin']['username'] : '';
        $expectedPass = isset($config['admin']['password']) ? (string)$config['admin']['password'] : '';
    }

    if ($expectedUser === '' || $expectedPass === '') {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
        }
        echo 'Admin auth is not configured';
        exit;
    }

    $cred = get_basic_auth_credentials();
    $user = $cred[0];
    $pass = $cred[1];

    if (!safe_hash_equals($expectedUser, $user) || !safe_hash_equals($expectedPass, $pass)) {
        if (!headers_sent()) {
            header('WWW-Authenticate: Basic realm="Overpass Cache Admin"');
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(401);
        }
        echo 'Authentication required';
        exit;
    }
}

function get_basic_auth_credentials()
{
    $user = isset($_SERVER['PHP_AUTH_USER']) ? (string)$_SERVER['PHP_AUTH_USER'] : '';
    $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string)$_SERVER['PHP_AUTH_PW'] : '';

    if ($user !== '' || $pass !== '') {
        return array($user, $pass);
    }

    $auth = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (stripos($auth, 'basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6), true);
        if ($decoded !== false) {
            $pos = strpos($decoded, ':');
            if ($pos !== false) {
                return array(substr($decoded, 0, $pos), substr($decoded, $pos + 1));
            }
        }
    }

    return array('', '');
}

function safe_hash_equals($a, $b)
{
    if (function_exists('hash_equals')) {
        return hash_equals((string)$a, (string)$b);
    }

    $a = (string)$a;
    $b = (string)$b;
    if (strlen($a) !== strlen($b)) {
        return false;
    }

    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function create_pdo($db)
{
    if (!class_exists('PDO')) {
        json_error(500, 'PDO extension is not available', array());
    }

    if (!is_array($db)) {
        json_error(500, 'Database config is invalid', array());
    }

    $host = isset($db['host']) ? (string)$db['host'] : '';
    $dbname = isset($db['dbname']) ? (string)$db['dbname'] : '';
    $charset = isset($db['charset']) ? (string)$db['charset'] : 'utf8mb4';
    $user = isset($db['user']) ? (string)$db['user'] : '';
    $pass = isset($db['pass']) ? (string)$db['pass'] : '';

    if ($host === '' || $dbname === '' || $user === '') {
        json_error(500, 'Database config is incomplete', array());
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $dbname, $charset);

    return new PDO($dsn, $user, $pass, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
}

function get_method()
{
    return isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';
}

function get_action()
{
    return isset($_GET['action']) ? (string)$_GET['action'] : 'list';
}

function handle_request()
{
    $config = load_config();
    require_admin_auth($config);

    $method = get_method();

    if ($method === 'GET') {
        $action = get_action();

        if ($action === 'ping') {
            json_response(array(
                'ok' => true,
                'php_version' => PHP_VERSION,
                'api_dir' => __DIR__,
                'config_found' => true,
                'pdo_loaded' => class_exists('PDO'),
            ));
        }

        if ($action === 'diag') {
            json_response(array(
                'ok' => true,
                'php_version' => PHP_VERSION,
                'api_dir' => __DIR__,
                'config_path' => dirname(dirname(__DIR__)) . '/config_overpass.php',
                'pdo_loaded' => class_exists('PDO'),
                'pdo_mysql_loaded' => extension_loaded('pdo_mysql'),
                'zlib_loaded' => extension_loaded('zlib'),
            ));
        }

        $pdo = create_pdo(isset($config['db']) ? $config['db'] : array());

        if ($action === 'list') {
            json_response(array(
                'ok' => true,
                'items' => list_caches($pdo, $_GET),
            ));
        }

        if ($action === 'summary') {
            json_response(array(
                'ok' => true,
                'summary' => get_summary($pdo),
            ));
        }

        if ($action === 'download') {
            download_caches($pdo, $_GET);
        }

        json_error(400, 'Unknown action', array('action' => $action));
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = array();

        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                json_error(400, 'Invalid JSON: ' . json_last_error_msg(), array());
            }
            if (!is_array($decoded)) {
                json_error(400, 'Invalid JSON', array());
            }
            $body = $decoded;
        }

        $action = isset($body['action']) ? (string)$body['action'] : '';
        $pdo = create_pdo(isset($config['db']) ? $config['db'] : array());

        if ($action === 'delete') {
            $keys = isset($body['keys']) ? $body['keys'] : null;
            if (!is_array($keys) || count($keys) === 0) {
                json_error(400, 'keys is required', array());
            }

            json_response(array(
                'ok' => true,
                'deleted' => delete_caches($pdo, $keys),
            ));
        }

        if ($action === 'delete_expired') {
            json_response(array(
                'ok' => true,
                'deleted' => delete_expired_caches($pdo),
            ));
        }

        if ($action === 'delete_older_than_days') {
            $daysRaw = isset($body['days']) ? $body['days'] : null;
            if (!(is_int($daysRaw) || (is_string($daysRaw) && preg_match('/^\d+$/', $daysRaw)))) {
                json_error(400, 'days must be integer', array());
            }

            $days = (int)$daysRaw;
            if ($days < 1 || $days > 3650) {
                json_error(400, 'days must be between 1 and 3650', array());
            }

            json_response(array(
                'ok' => true,
                'deleted' => delete_caches_older_than_days($pdo, $days),
                'days' => $days,
            ));
        }

        json_error(400, 'Unknown action', array('action' => $action));
    }

    json_error(405, 'GET or POST only', array('method' => $method));
}

function list_caches($pdo, $query)
{
    $limit = isset($query['limit']) ? (int)$query['limit'] : 500;
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 2000) {
        $limit = 2000;
    }

    $includeExpired = isset($query['includeExpired']) && (string)$query['includeExpired'] === '1';

    $params = array();
    $where = array();

    if (!$includeExpired) {
        $where[] = 'expires_at > UTC_TIMESTAMP()';
    }

    $bbox = parse_optional_bbox($query);
    if ($bbox !== null) {
        $south = $bbox[0];
        $west = $bbox[1];
        $north = $bbox[2];
        $east = $bbox[3];

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

    if (count($where) > 0) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY expires_at DESC, created_at DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll();
    $items = array();

    foreach ($rows as $row) {
        $items[] = array(
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
        );
    }

    return $items;
}

function get_summary($pdo)
{
    $sql = 'SELECT
                COUNT(*) AS total_count,
                SUM(expires_at > UTC_TIMESTAMP()) AS active_count,
                SUM(expires_at <= UTC_TIMESTAMP()) AS expired_count,
                COALESCE(SUM(OCTET_LENGTH(response_body)), 0) AS total_bytes
            FROM overpass_cache';

    $row = $pdo->query($sql)->fetch();

    return array(
        'total_count' => isset($row['total_count']) ? (int)$row['total_count'] : 0,
        'active_count' => isset($row['active_count']) ? (int)$row['active_count'] : 0,
        'expired_count' => isset($row['expired_count']) ? (int)$row['expired_count'] : 0,
        'total_bytes' => isset($row['total_bytes']) ? (int)$row['total_bytes'] : 0,
    );
}

function download_caches($pdo, $query)
{
    $keys = parse_cache_keys_for_download($query);

    if (count($keys) > 100) {
        json_error(400, 'Too many keys. Download up to 100 cache entries at once.', array());
    }

    $items = read_caches_for_download($pdo, $keys);
    if (count($items) === 0) {
        json_error(404, 'Selected cache was not found', array());
    }

    if (count($keys) === 1 && count($items) === 1) {
        $item = $items[0];
        $body = decode_cache_body((string)$item['response_body'], (string)$item['body_encoding']);
        if ($body === null) {
            json_error(500, 'Failed to decode cache body', array());
        }

        header('Content-Type: ' . safe_content_type((string)$item['content_type']));
        header('Content-Disposition: attachment; filename="' . make_single_cache_download_filename($item) . '"');
        header('X-Cache-Download-Count: 1');
        echo $body;
        exit;
    }

    $exportItems = array();
    foreach ($items as $item) {
        $body = decode_cache_body((string)$item['response_body'], (string)$item['body_encoding']);
        if ($body === null) {
            $body = '';
        }

        $exportItems[] = array(
            'cache_key' => (string)$item['cache_key'],
            'query_hash' => (string)$item['query_hash'],
            'normalized_bbox' => (string)$item['normalized_bbox'],
            'content_type' => (string)$item['content_type'],
            'status_code' => (int)$item['status_code'],
            'body_encoding' => (string)$item['body_encoding'],
            'created_at_utc' => (string)$item['created_at_utc'],
            'expires_at_utc' => (string)$item['expires_at_utc'],
            'response_body' => $body,
        );
    }

    $json = json_encode(array(
        'exported_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'count' => count($exportItems),
        'items' => $exportItems,
    ), cache_admin_json_flags());

    if (!is_string($json)) {
        json_error(500, 'Failed to encode download JSON', array());
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="overpass_cache_selected_' . gmdate('Ymd_His') . 'Z.json"');
    header('X-Cache-Download-Count: ' . count($exportItems));
    echo $json;
    exit;
}

function parse_cache_keys_for_download($query)
{
    $rawValues = array();
    $names = array('key', 'keys');

    foreach ($names as $name) {
        if (!array_key_exists($name, $query)) {
            continue;
        }

        $value = $query[$name];
        if (is_array($value)) {
            foreach ($value as $v) {
                if (is_string($v)) {
                    $rawValues[] = $v;
                }
            }
        } elseif (is_string($value)) {
            $parts = preg_split('/[\s,]+/', $value);
            if (is_array($parts)) {
                foreach ($parts as $v) {
                    if ($v !== '') {
                        $rawValues[] = $v;
                    }
                }
            }
        }
    }

    $keys = array();
    foreach ($rawValues as $value) {
        $key = strtolower(trim($value));
        if ($key === '') {
            continue;
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            json_error(400, 'Invalid cache key', array());
        }
        $keys[] = $key;
    }

    $keys = array_values(array_unique($keys));
    if (count($keys) === 0) {
        json_error(400, 'key or keys is required', array());
    }

    return $keys;
}

function read_caches_for_download($pdo, $keys)
{
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $sql = 'SELECT
                cache_key,
                query_hash,
                normalized_bbox,
                response_body,
                body_encoding,
                content_type,
                status_code,
                DATE_FORMAT(created_at, "%Y-%m-%dT%H:%i:%sZ") AS created_at_utc,
                DATE_FORMAT(expires_at, "%Y-%m-%dT%H:%i:%sZ") AS expires_at_utc
            FROM overpass_cache
            WHERE cache_key IN (' . $placeholders . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($keys);
    $rows = $stmt->fetchAll();

    $byKey = array();
    foreach ($rows as $row) {
        $byKey[(string)$row['cache_key']] = $row;
    }

    $ordered = array();
    foreach ($keys as $key) {
        if (isset($byKey[$key])) {
            $ordered[] = $byKey[$key];
        }
    }

    return $ordered;
}

function decode_cache_body($body, $encoding)
{
    if ($encoding === 'gzip') {
        if (!function_exists('gzdecode')) {
            return null;
        }
        $decoded = gzdecode($body);
        return is_string($decoded) ? $decoded : null;
    }

    return $body;
}

function safe_content_type($contentType)
{
    $contentType = trim((string)$contentType);
    if ($contentType === '' || preg_match('/[\r\n]/', $contentType)) {
        return 'application/octet-stream';
    }
    return $contentType;
}

function make_single_cache_download_filename($item)
{
    $key = substr((string)$item['cache_key'], 0, 12);
    $contentType = strtolower((string)$item['content_type']);

    $ext = 'dat';
    if (strpos($contentType, 'json') !== false) {
        $ext = 'json';
    } elseif (strpos($contentType, 'xml') !== false) {
        $ext = 'xml';
    } elseif (strpos($contentType, 'text') !== false) {
        $ext = 'txt';
    }

    return 'overpass_cache_' . gmdate('Ymd_His') . 'Z_' . $key . '.' . $ext;
}

function delete_caches($pdo, $keys)
{
    $clean = array();
    foreach ($keys as $v) {
        if (is_string($v)) {
            $key = strtolower(trim($v));
            if ($key !== '' && preg_match('/\A[a-f0-9]{64}\z/', $key)) {
                $clean[] = $key;
            }
        }
    }

    $clean = array_values(array_unique($clean));
    if (count($clean) === 0) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $stmt = $pdo->prepare('DELETE FROM overpass_cache WHERE cache_key IN (' . $placeholders . ')');
    $stmt->execute($clean);
    return $stmt->rowCount();
}

function delete_expired_caches($pdo)
{
    $stmt = $pdo->prepare('DELETE FROM overpass_cache WHERE expires_at <= UTC_TIMESTAMP()');
    $stmt->execute();
    return $stmt->rowCount();
}

function delete_caches_older_than_days($pdo, $days)
{
    $days = (int)$days;
    $sql = 'DELETE FROM overpass_cache WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $days . ' DAY)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->rowCount();
}

function parse_optional_bbox($query)
{
    if (isset($query['bbox']) && is_string($query['bbox']) && trim($query['bbox']) !== '') {
        $parts = preg_split('/\s*,\s*/', trim($query['bbox']));
        if (is_array($parts) && count($parts) === 4) {
            return array((float)$parts[0], (float)$parts[1], (float)$parts[2], (float)$parts[3]);
        }
    }

    $required = array('south', 'west', 'north', 'east');
    foreach ($required as $key) {
        if (!array_key_exists($key, $query)) {
            return null;
        }
    }

    return array(
        (float)$query['south'],
        (float)$query['west'],
        (float)$query['north'],
        (float)$query['east'],
    );
}

try {
    handle_request();
} catch (PDOException $e) {
    json_error(500, 'Database error: ' . $e->getMessage(), array());
} catch (Exception $e) {
    json_error(500, 'Server error: ' . $e->getMessage(), array());
}
