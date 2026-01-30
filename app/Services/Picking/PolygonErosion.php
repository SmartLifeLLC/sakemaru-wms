<?php

namespace App\Services\Picking;

/**
 * Polygon erosion (negative buffer) for walkable area sanitization
 * Shrinks polygons to account for cart/picker physical width
 */
class PolygonErosion
{
    /**
     * Erode (shrink) polygons by specified distance
     *
     * @param  array  $polygons  Array of polygons
     * @param  int  $distance  Erosion distance in pixels (cart radius)
     * @return array Eroded polygons
     */
    public function erode(array $polygons, int $distance): array
    {
        $eroded = [];

        foreach ($polygons as $polygon) {
            // Simple erosion: move each vertex inward along bisector
            $outer = $polygon['outer'];
            $erodedOuter = $this->erodeRing($outer, $distance);

            if (count($erodedOuter) >= 3) {
                $eroded[] = [
                    'outer' => $erodedOuter,
                    'holes' => [], // Holes are complex, skip for MVP
                ];
            }
        }

        return $eroded;
    }

    /**
     * Erode a polygon ring (move vertices inward)
     *
     * @param  array  $ring  Array of [x,y] points
     * @param  int  $distance  Distance to erode
     * @return array Eroded ring
     */
    private function erodeRing(array $ring, int $distance): array
    {
        $n = count($ring);
        if ($n < 3) {
            return [];
        }

        $eroded = [];

        for ($i = 0; $i < $n; $i++) {
            $prev = $ring[($i - 1 + $n) % $n];
            $curr = $ring[$i];
            $next = $ring[($i + 1) % $n];

            // Calculate inward normal at current vertex
            $normal = $this->calculateInwardNormal($prev, $curr, $next);

            // Move vertex inward
            $eroded[] = [
                $curr[0] + $normal[0] * $distance,
                $curr[1] + $normal[1] * $distance,
            ];
        }

        // Remove degenerate edges (self-intersections from excessive erosion)
        return $this->removeDegenerateEdges($eroded);
    }

    /**
     * Calculate inward normal at vertex
     *
     * @param  array  $prev  Previous vertex
     * @param  array  $curr  Current vertex
     * @param  array  $next  Next vertex
     * @return array Normalized inward normal [x, y]
     */
    private function calculateInwardNormal(array $prev, array $curr, array $next): array
    {
        // Edge vectors
        $v1 = [$curr[0] - $prev[0], $curr[1] - $prev[1]];
        $v2 = [$next[0] - $curr[0], $next[1] - $curr[1]];

        // Normalize vectors
        $v1 = $this->normalize($v1);
        $v2 = $this->normalize($v2);

        // Perpendiculars (rotate 90° clockwise for inward)
        $p1 = [$v1[1], -$v1[0]];
        $p2 = [$v2[1], -$v2[0]];

        // Average bisector
        $bisector = [
            ($p1[0] + $p2[0]) / 2,
            ($p1[1] + $p2[1]) / 2,
        ];

        return $this->normalize($bisector);
    }

    /**
     * Normalize vector
     */
    private function normalize(array $v): array
    {
        $len = sqrt($v[0] * $v[0] + $v[1] * $v[1]);
        if ($len == 0) {
            return [0, 0];
        }

        return [$v[0] / $len, $v[1] / $len];
    }

    /**
     * Remove degenerate edges from polygon
     *
     * @param  array  $ring  Polygon ring
     * @return array Cleaned ring
     */
    private function removeDegenerateEdges(array $ring): array
    {
        $cleaned = [];
        $n = count($ring);

        for ($i = 0; $i < $n; $i++) {
            $curr = $ring[$i];
            $next = $ring[($i + 1) % $n];

            // Keep vertex if it forms a valid edge (distance > threshold)
            $dist = sqrt(
                pow($next[0] - $curr[0], 2) +
                pow($next[1] - $curr[1], 2)
            );

            if ($dist > 1.0) { // Minimum edge length
                $cleaned[] = $curr;
            }
        }

        return $cleaned;
    }
}
