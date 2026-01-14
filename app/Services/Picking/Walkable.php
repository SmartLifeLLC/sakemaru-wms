<?php

namespace App\Services\Picking;

/**
 * Walkable area management for picking route optimization
 * Handles polygon-based walkable area definition and point containment checks
 */
class Walkable
{
    private array $polygons;

    /**
     * @param  array  $polygons  Array of polygons with format:
     *                           [['outer' => [[x,y],...], 'holes' => [[[x,y],...],...]],...]
     */
    public function __construct(array $polygons)
    {
        $this->polygons = $polygons;
    }

    /**
     * Check if a point is inside the walkable area
     *
     * @param  array  $p  Point [x, y]
     * @return bool True if point is in walkable area
     */
    public function contains(array $p): bool
    {
        foreach ($this->polygons as $poly) {
            // Check if point is in outer polygon
            if ($this->pointInPolygon($p, $poly['outer'])) {
                // Check if point is NOT in any hole
                foreach ($poly['holes'] ?? [] as $hole) {
                    if ($this->pointInPolygon($p, $hole)) {
                        return false; // Point is in hole, not walkable
                    }
                }

                return true; // Point is in outer and not in any hole
            }
        }

        return false;
    }

    /**
     * Find nearest point on the boundary of walkable area
     *
     * @param  array  $p  Point [x, y]
     * @return array Nearest point [x, y] on boundary
     */
    public function nearestPointOnBoundary(array $p): array
    {
        $best = null;
        $bestDist = PHP_FLOAT_MAX;

        foreach ($this->polygons as $poly) {
            // Check outer boundary
            foreach ([$poly['outer'], ...($poly['holes'] ?? [])] as $ring) {
                $n = count($ring);
                for ($i = 0; $i < $n; $i++) {
                    $a = $ring[$i];
                    $b = $ring[($i + 1) % $n];
                    $q = $this->nearestPointOnSegment($p, $a, $b);
                    $d = $this->sqDistance($p, $q);

                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best = $q;
                    }
                }
            }
        }

        return $best ?? $p;
    }

    /**
     * Nudge a boundary point slightly inside the walkable area
     *
     * @param  array  $q  Point on boundary [x, y]
     * @param  int  $eps  Distance to nudge (px)
     * @return array Point nudged inside [x, y]
     */
    public function nudgeInside(array $q, int $eps = 2): array
    {
        // Simple approach: try nudging in multiple directions
        // and pick the first one that's inside
        $directions = [
            [0, -$eps],  // up
            [0, $eps],   // down
            [-$eps, 0],  // left
            [$eps, 0],   // right
            [-$eps, -$eps], // up-left
            [$eps, -$eps],  // up-right
            [-$eps, $eps],  // down-left
            [$eps, $eps],   // down-right
        ];

        foreach ($directions as [$dx, $dy]) {
            $candidate = [$q[0] + $dx, $q[1] + $dy];
            if ($this->contains($candidate)) {
                return $candidate;
            }
        }

        // If no direction works, return original point
        return $q;
    }

    /**
     * Point-in-Polygon test using ray casting algorithm
     *
     * @param  array  $p  Point [x, y]
     * @param  array  $polygon  Polygon vertices [[x,y],...]
     * @return bool True if point is inside polygon
     */
    private function pointInPolygon(array $p, array $polygon): bool
    {
        $x = $p[0];
        $y = $p[1];
        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];
            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Find nearest point on a line segment
     *
     * @param  array  $p  Point [x, y]
     * @param  array  $a  Segment start [x, y]
     * @param  array  $b  Segment end [x, y]
     * @return array Nearest point [x, y]
     */
    private function nearestPointOnSegment(array $p, array $a, array $b): array
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lengthSq = $dx * $dx + $dy * $dy;

        if ($lengthSq == 0) {
            return $a; // Segment is a point
        }

        // Parameter t of nearest point on line
        $t = max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $lengthSq));

        return [
            $a[0] + $t * $dx,
            $a[1] + $t * $dy,
        ];
    }

    /**
     * Calculate squared distance between two points
     *
     * @param  array  $a  Point [x, y]
     * @param  array  $b  Point [x, y]
     * @return float Squared distance
     */
    private function sqDistance(array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];

        return $dx * $dx + $dy * $dy;
    }

    /**
     * Get all polygons
     */
    public function getPolygons(): array
    {
        return $this->polygons;
    }
}
