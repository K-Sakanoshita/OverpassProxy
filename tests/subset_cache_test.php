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

// All cached relations are retained regardless of the requested bbox.
// Their available relation/way/node dependencies must also be retained.
// The relation cycle verifies queue + visited processing does not loop.
$relationSource = json_encode([
    'elements' => [
        [
            'type' => 'relation',
            'id' => 40,
            'members' => [
                ['type' => 'way', 'ref' => 200, 'role' => 'outer'],
                ['type' => 'relation', 'ref' => 41, 'role' => 'subarea'],
                ['type' => 'node', 'ref' => 900, 'role' => 'label'],
            ],
            'tags' => ['type' => 'multipolygon'],
        ],
        [
            'type' => 'relation',
            'id' => 41,
            'members' => [
                ['type' => 'way', 'ref' => 201, 'role' => 'outer'],
                ['type' => 'relation', 'ref' => 40, 'role' => 'parent'],
            ],
            'tags' => ['type' => 'multipolygon'],
        ],
        // These ways are far outside the requested bbox but are relation members.
        ['type' => 'way', 'id' => 200, 'nodes' => [901, 902]],
        ['type' => 'way', 'id' => 201, 'nodes' => [903, 904]],
        // This distant way is unrelated and should still be removed.
        ['type' => 'way', 'id' => 299, 'nodes' => [905, 906]],
        ['type' => 'node', 'id' => 900, 'lat' => 36.0000, 'lon' => 137.0000],
        ['type' => 'node', 'id' => 901, 'lat' => 36.0000, 'lon' => 137.0000],
        ['type' => 'node', 'id' => 902, 'lat' => 36.0100, 'lon' => 137.0100],
        ['type' => 'node', 'id' => 903, 'lat' => 36.0200, 'lon' => 137.0200],
        ['type' => 'node', 'id' => 904, 'lat' => 36.0300, 'lon' => 137.0300],
        ['type' => 'node', 'id' => 905, 'lat' => 37.0000, 'lon' => 138.0000],
        ['type' => 'node', 'id' => 906, 'lat' => 37.0100, 'lon' => 138.0100],
    ],
], JSON_THROW_ON_ERROR);

$relationResult = ProxySubsetCache::extract(
    $relationSource,
    [34.0000, 135.0000, 34.1000, 135.1000]
);
$relationDecoded = decodeResult($relationResult);
$relationKeys = array_map(
    static fn(array $element): string => $element['type'] . '/' . $element['id'],
    $relationDecoded['elements']
);

foreach (['relation/40', 'relation/41', 'way/200', 'way/201', 'node/900', 'node/901', 'node/902', 'node/903', 'node/904'] as $key) {
    expectTrue(in_array($key, $relationKeys, true), "{$key} must be retained as relation content/dependency");
}
expectTrue(!in_array('way/299', $relationKeys, true), 'unrelated distant way must be removed');
expectTrue(!in_array('node/905', $relationKeys, true), 'unrelated distant node must be removed');
expectTrue($relationResult['selected_relations'] === 2, 'all cached relations must be reported');
expectTrue($relationResult['selected_ways'] === 2, 'relation member ways must be reported');

// A relation-member way is kept even when the cached response does not contain
// all of its referenced node coordinates. We preserve what the source cache had
// rather than forcing a new upstream request.
$partialRelationSource = json_encode([
    'elements' => [
        [
            'type' => 'relation',
            'id' => 50,
            'members' => [
                ['type' => 'way', 'ref' => 500, 'role' => 'outer'],
            ],
        ],
        ['type' => 'way', 'id' => 500, 'nodes' => [1000, 1001]],
        ['type' => 'node', 'id' => 1000, 'lat' => 36.0, 'lon' => 137.0],
    ],
], JSON_THROW_ON_ERROR);

$partialRelationResult = ProxySubsetCache::extract(
    $partialRelationSource,
    [34.0, 135.0, 34.1, 135.1]
);
$partialDecoded = decodeResult($partialRelationResult);
$partialKeys = array_map(
    static fn(array $element): string => $element['type'] . '/' . $element['id'],
    $partialDecoded['elements']
);
expectTrue(in_array('relation/50', $partialKeys, true), 'relation with partial dependencies must be retained');
expectTrue(in_array('way/500', $partialKeys, true), 'available relation member way must be retained');
expectTrue(in_array('node/1000', $partialKeys, true), 'available relation member node must be retained');

// An independent way without bounds/geometry and without all referenced node
// coordinates still cannot be spatially classified safely.
$incompleteSource = json_encode([
    'elements' => [
        ['type' => 'way', 'id' => 60, 'nodes' => [1100, 1101]],
        ['type' => 'node', 'id' => 1100, 'lat' => 34.0, 'lon' => 135.0],
    ],
], JSON_THROW_ON_ERROR);

expectTrue(
    ProxySubsetCache::extract($incompleteSource, [34.0, 135.0, 34.1, 135.1]) === null,
    'incomplete independent way geometry must fall back'
);

fwrite(STDOUT, "OK: subset cache tests passed\n");
