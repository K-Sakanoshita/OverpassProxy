<?php

declare(strict_types=1);

/**
 * Conservative subset extraction for cached Overpass JSON responses.
 *
 * Nodes and ways are spatially reduced, while relations are deliberately kept
 * intact. Dependencies referenced by cached relations are kept when they are
 * present in the same cached response.
 */
final class ProxySubsetCache
{
    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $requestedBBox
     * @return array{
     *   body: string,
     *   source_elements: int,
     *   returned_elements: int,
     *   selected_ways: int,
     *   selected_relations: int
     * }|null
     */
    public static function extract(string $sourceBody, array $requestedBBox): ?array
    {
        try {
            $document = json_decode($sourceBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($document) || !isset($document['elements']) || !is_array($document['elements'])) {
            return null;
        }

        // Do not transform cached error-like Overpass responses.
        if (isset($document['remark'])) {
            return null;
        }

        $elements = $document['elements'];
        $nodesById = [];
        $waysById = [];
        $relationsById = [];

        foreach ($elements as $index => $element) {
            if (!is_array($element) || !isset($element['type'], $element['id'])) {
                return null;
            }

            $type = (string)$element['type'];
            $id = (string)$element['id'];

            if ($type === 'node') {
                if (!self::hasCoordinate($element)) {
                    return null;
                }

                $nodesById[$id] = [
                    'index' => $index,
                    'lat' => (float)$element['lat'],
                    'lon' => (float)$element['lon'],
                ];
                continue;
            }

            if ($type === 'way') {
                $waysById[$id] = ['index' => $index];
                continue;
            }

            if ($type === 'relation') {
                if (isset($element['members']) && !is_array($element['members'])) {
                    return null;
                }

                $relationsById[$id] = ['index' => $index];
                continue;
            }

            // area / derived elements / unsupported result shapes must fall back.
            return null;
        }

        $keep = [];

        // Preserve nodes spatially inside the requested bbox. Way/relation
        // dependencies outside the bbox are added below.
        foreach ($nodesById as $node) {
            if (self::pointInBBox($node['lat'], $node['lon'], $requestedBBox)) {
                $keep[$node['index']] = true;
            }
        }

        // Relations are intentionally not spatially subsetted. Keep every
        // relation in the cached response, then preserve all dependencies that
        // are also present in that response. A queue + visited set handles
        // relation -> relation references and cycles safely.
        $relationQueue = array_keys($relationsById);
        $visitedRelations = [];
        $cursor = 0;

        while ($cursor < count($relationQueue)) {
            $relationId = (string)$relationQueue[$cursor++];
            if (isset($visitedRelations[$relationId])) {
                continue;
            }
            $visitedRelations[$relationId] = true;

            if (!isset($relationsById[$relationId])) {
                continue;
            }

            $relationIndex = $relationsById[$relationId]['index'];
            $keep[$relationIndex] = true;
            $relation = $elements[$relationIndex];
            $members = $relation['members'] ?? [];

            foreach ($members as $member) {
                if (!is_array($member) || !isset($member['type'], $member['ref'])) {
                    return null;
                }

                $memberType = (string)$member['type'];
                $memberId = (string)$member['ref'];

                if ($memberType === 'node') {
                    if (isset($nodesById[$memberId])) {
                        $keep[$nodesById[$memberId]['index']] = true;
                    }
                    continue;
                }

                if ($memberType === 'way') {
                    if (isset($waysById[$memberId])) {
                        $wayIndex = $waysById[$memberId]['index'];
                        $keep[$wayIndex] = true;
                        self::keepWayNodes($elements[$wayIndex], $nodesById, $keep);
                    }
                    continue;
                }

                if ($memberType === 'relation') {
                    if (isset($relationsById[$memberId]) && !isset($visitedRelations[$memberId])) {
                        $relationQueue[] = $memberId;
                    }
                    continue;
                }

                return null;
            }
        }

        // For ways not already retained as relation dependencies, use a
        // conservative bbox intersection test. The way itself is never clipped.
        foreach ($elements as $index => $element) {
            if (!is_array($element) || ($element['type'] ?? null) !== 'way') {
                continue;
            }

            if (isset($keep[$index])) {
                continue;
            }

            $wayBBox = self::wayBBox($element, $nodesById);
            if ($wayBBox === null) {
                // We cannot safely decide whether this independent way intersects
                // the request, so let the caller fall back to the upstream path.
                return null;
            }

            if (!self::bboxIntersects($wayBBox, $requestedBBox)) {
                continue;
            }

            $keep[$index] = true;
            self::keepWayNodes($element, $nodesById, $keep);
        }

        $filtered = [];
        $selectedWays = 0;
        $selectedRelations = 0;

        foreach ($elements as $index => $element) {
            if (!isset($keep[$index])) {
                continue;
            }

            $filtered[] = $element;
            if (($element['type'] ?? null) === 'way') {
                $selectedWays++;
            } elseif (($element['type'] ?? null) === 'relation') {
                $selectedRelations++;
            }
        }

        $document['elements'] = $filtered;

        try {
            $body = json_encode(
                $document,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            return null;
        }

        return [
            'body' => $body,
            'source_elements' => count($elements),
            'returned_elements' => count($filtered),
            'selected_ways' => $selectedWays,
            'selected_relations' => $selectedRelations,
        ];
    }

    /**
     * @param array<string, array{index: int|string, lat: float, lon: float}> $nodesById
     * @param array<int|string, bool> $keep
     */
    private static function keepWayNodes(array $way, array $nodesById, array &$keep): void
    {
        if (!isset($way['nodes']) || !is_array($way['nodes'])) {
            return;
        }

        foreach ($way['nodes'] as $nodeId) {
            $key = (string)$nodeId;
            if (isset($nodesById[$key])) {
                $keep[$nodesById[$key]['index']] = true;
            }
        }
    }

    private static function hasCoordinate(array $node): bool
    {
        if (!isset($node['lat'], $node['lon'])) {
            return false;
        }

        $lat = (float)$node['lat'];
        $lon = (float)$node['lon'];

        return is_finite($lat)
            && is_finite($lon)
            && $lat >= -90.0
            && $lat <= 90.0
            && $lon >= -180.0
            && $lon <= 180.0;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    private static function pointInBBox(float $lat, float $lon, array $bbox): bool
    {
        $epsilon = 1e-9;

        return $lat + $epsilon >= $bbox[0]
            && $lon + $epsilon >= $bbox[1]
            && $lat <= $bbox[2] + $epsilon
            && $lon <= $bbox[3] + $epsilon;
    }

    /**
     * @param array<string, array{index: int|string, lat: float, lon: float}> $nodesById
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function wayBBox(array $way, array $nodesById): ?array
    {
        $fromBounds = self::bboxFromBounds($way['bounds'] ?? null);
        if ($fromBounds !== null) {
            return $fromBounds;
        }

        $fromGeometry = self::bboxFromGeometry($way['geometry'] ?? null);
        if ($fromGeometry !== null) {
            return $fromGeometry;
        }

        if (!isset($way['nodes']) || !is_array($way['nodes']) || $way['nodes'] === []) {
            return null;
        }

        $points = [];
        foreach ($way['nodes'] as $nodeId) {
            $key = (string)$nodeId;
            if (!isset($nodesById[$key])) {
                return null;
            }

            $points[] = [
                'lat' => $nodesById[$key]['lat'],
                'lon' => $nodesById[$key]['lon'],
            ];
        }

        return self::bboxFromGeometry($points);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function bboxFromBounds(mixed $bounds): ?array
    {
        if (!is_array($bounds)) {
            return null;
        }

        $required = ['minlat', 'minlon', 'maxlat', 'maxlon'];
        foreach ($required as $key) {
            if (!isset($bounds[$key]) || !is_numeric($bounds[$key])) {
                return null;
            }
        }

        $bbox = [
            (float)$bounds['minlat'],
            (float)$bounds['minlon'],
            (float)$bounds['maxlat'],
            (float)$bounds['maxlon'],
        ];

        if (!self::validBBox($bbox)) {
            return null;
        }

        return $bbox;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function bboxFromGeometry(mixed $geometry): ?array
    {
        if (!is_array($geometry) || $geometry === []) {
            return null;
        }

        $south = INF;
        $west = INF;
        $north = -INF;
        $east = -INF;

        foreach ($geometry as $point) {
            if (!is_array($point) || !isset($point['lat'], $point['lon'])) {
                return null;
            }

            $lat = (float)$point['lat'];
            $lon = (float)$point['lon'];
            if (!is_finite($lat) || !is_finite($lon)) {
                return null;
            }

            $south = min($south, $lat);
            $west = min($west, $lon);
            $north = max($north, $lat);
            $east = max($east, $lon);
        }

        $bbox = [$south, $west, $north, $east];
        if (!self::validBBox($bbox, true)) {
            return null;
        }

        return $bbox;
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $bbox
     */
    private static function validBBox(array $bbox, bool $allowZeroSpan = false): bool
    {
        foreach ($bbox as $value) {
            if (!is_finite($value)) {
                return false;
            }
        }

        if ($bbox[0] < -90.0 || $bbox[2] > 90.0 || $bbox[1] < -180.0 || $bbox[3] > 180.0) {
            return false;
        }

        return $allowZeroSpan
            ? $bbox[0] <= $bbox[2] && $bbox[1] <= $bbox[3]
            : $bbox[0] < $bbox[2] && $bbox[1] < $bbox[3];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     */
    private static function bboxIntersects(array $a, array $b): bool
    {
        $epsilon = 1e-9;

        return $a[2] + $epsilon >= $b[0]
            && $a[0] <= $b[2] + $epsilon
            && $a[3] + $epsilon >= $b[1]
            && $a[1] <= $b[3] + $epsilon;
    }
}
