<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/www/api/lib/SubsetCache.php';

function expectTrue(bool $value, string $message): void
{
    if (!$value) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function decodeResult(?array $result): array
{
    expectTrue($result !== null, 'expected subset result');
    $decoded = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
    expectTrue(is_array($decoded), 'subset result must decode');
    return $decoded;
}

// Way intersects the requested bbox and must be kept whole, including a node
// outside the requested bbox. A distant way and its nodes should be dropped.
$source = json_encode([
    'version' => 0.6,
    'elements' => [
        ['type' => 'way', 'id' => 10, 'nodes' => [1, 2], 'tags' => ['highway' => 'residential']],
        ['type' => 'way', 'id' => 20, 'nodes' => [3, 4], 'tags' => ['highway' => 'service']],
        ['type' => 'node', 'id' => 1, 'lat' => 34.0000, 'lon' => 135.0000],
        ['type' => 'node', 'id' => 2, 'lat' => 34.0200, 'lon' => 135.0200],
        ['type' => 'node', 'id' => 3, 'lat' => 35.0000, 'lon' => 136.0000],
        ['type' => 'node', 'id' => 4, 'lat' => 35.0100, 'lon' => 136.0100],
    ],
], JSON_THROW_ON_ERROR);

$result = ProxySubsetCache::extract($source, [34.0050, 135.0050, 34.0100, 135.0100]);
$decoded = decodeResult($result);
$keys = array_map(
    static fn(array $element): string => $element['type'] . '/' . $element['id'],
    $decoded['elements']
);

expectTrue(in_array('way/10', $keys, true), 'intersecting way must be kept');
expectTrue(in_array('node/1', $keys, true), 'way dependency node outside bbox must be kept');
expectTrue(in_array('node/2', $keys, true), 'way dependency node outside bbox must be kept');
expectTrue(!in_array('way/20', $keys, true), 'distant way must be removed');
expectTrue(!in_array('node/3', $keys, true), 'distant dependency node must be removed');
expectTrue($result['selected_ways'] === 1, 'selected way count must be reported');

// A way with inline geometry can be subsetted even if node elements are absent.
$geometrySource = json_encode([
    'elements' => [
        [
            'type' => 'way',
            'id' => 30,
            'geometry' => [
                ['lat' => 34.0000, 'lon' => 135.0000],
                ['lat' => 34.0200, 'lon' => 135.0200],
            ],
            'tags' => ['building' => 'yes'],
        ],
    ],
], JSON_THROW_ON_ERROR);

$geometryResult = ProxySubsetCache::extract(
    $geometrySource,
    [34.0050, 135.0050, 34.0100, 135.0100]
);
$geometryDecoded = decodeResult($geometryResult);
expectTrue(count($geometryDecoded['elements']) === 1, 'geometry way must be retained');

// Relation handling is intentionally deferred. Presence of a relation must
// reject subset extraction so the caller can safely fall back to upstream.
$relationSource = json_encode([
    'elements' => [
        ['type' => 'relation', 'id' => 40, 'members' => []],
    ],
], JSON_THROW_ON_ERROR);

expectTrue(
    ProxySubsetCache::extract($relationSource, [34.0, 135.0, 34.1, 135.1]) === null,
    'relation response must fall back in phase 1'
);

// A way without bounds/geometry and without all referenced node coordinates
// cannot be classified safely.
$incompleteSource = json_encode([
    'elements' => [
        ['type' => 'way', 'id' => 50, 'nodes' => [100, 101]],
        ['type' => 'node', 'id' => 100, 'lat' => 34.0, 'lon' => 135.0],
    ],
], JSON_THROW_ON_ERROR);

expectTrue(
    ProxySubsetCache::extract($incompleteSource, [34.0, 135.0, 34.1, 135.1]) === null,
    'incomplete way geometry must fall back'
);

fwrite(STDOUT, "OK: subset cache tests passed\n");
