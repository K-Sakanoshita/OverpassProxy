<?php

declare(strict_types=1);

final class ProxyRawQueryBbox
{
    /**
     * Extracts a cache identity template and the bbox used for routing/grid selection.
     *
     * A global [bbox:...] is preferred. Otherwise every inline bbox is replaced by
     * one shared placeholder and the union of those bboxes is returned.
     *
     * @return array{
     *   0: array{0: float, 1: float, 2: float, 3: float},
     *   1: string
     * }|null
     */
    public static function extract(string $data): ?array
    {
        $number = '[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?';
        $globalPattern = '/\[bbox\s*:\s*(' . $number . ')\s*,\s*(' . $number . ')\s*,\s*(' . $number . ')\s*,\s*(' . $number . ')\s*\]/i';

        if (preg_match($globalPattern, $data, $match)) {
            $bbox = [
                (float)$match[1],
                (float)$match[2],
                (float)$match[3],
                (float)$match[4],
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
        foreach ($matches as $match) {
            $bboxes[] = [
                (float)$match[3],
                (float)$match[4],
                (float)$match[5],
                (float)$match[6],
            ];
        }

        $south = $bboxes[0][0];
        $west = $bboxes[0][1];
        $north = $bboxes[0][2];
        $east = $bboxes[0][3];

        foreach ($bboxes as $bbox) {
            $south = min($south, $bbox[0]);
            $west = min($west, $bbox[1]);
            $north = max($north, $bbox[2]);
            $east = max($east, $bbox[3]);
        }

        $template = preg_replace($inlinePattern, '$1$2({{bbox}})', $data);
        if (!is_string($template)) {
            return null;
        }

        return [[$south, $west, $north, $east], $template];
    }
}
