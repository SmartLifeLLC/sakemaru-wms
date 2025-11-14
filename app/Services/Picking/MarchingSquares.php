<?php

namespace App\Services\Picking;

/**
 * Marching Squares algorithm for bitmap to polygon conversion
 * Converts a 2D bitmap grid into polygon contours
 */
class MarchingSquares
{
    /**
     * Convert bitmap to polygons
     *
     * @param array $bitmap 2D array of boolean values (true = walkable)
     * @param int $cellSize Size of each cell in pixels
     * @return array Array of polygons [['outer' => [[x,y],...], 'holes' => []],...]
     */
    public function bitmapToPolygons(array $bitmap, int $cellSize): array
    {
        if (empty($bitmap)) {
            return [];
        }

        $height = count($bitmap);
        $width = count($bitmap[0]);

        // Build contours
        $contours = $this->findContours($bitmap, $width, $height);

        // Convert contours to polygons with proper scaling
        $polygons = [];
        foreach ($contours as $contour) {
            $scaledContour = array_map(function ($point) use ($cellSize) {
                return [$point[0] * $cellSize, $point[1] * $cellSize];
            }, $contour);

            $polygons[] = [
                'outer' => $scaledContour,
                'holes' => [],
            ];
        }

        return $polygons;
    }

    /**
     * Find contours in bitmap using marching squares
     *
     * @param array $bitmap 2D array
     * @param int $width Width of bitmap
     * @param int $height Height of bitmap
     * @return array Array of contours (each contour is array of [x,y] points)
     */
    private function findContours(array $bitmap, int $width, int $height): array
    {
        $visited = array_fill(0, $height, array_fill(0, $width, false));
        $contours = [];

        // Scan for contour starting points
        for ($y = 0; $y < $height - 1; $y++) {
            for ($x = 0; $x < $width - 1; $x++) {
                if (!$visited[$y][$x] && $this->isContourStart($bitmap, $x, $y, $width, $height)) {
                    $contour = $this->traceContour($bitmap, $x, $y, $width, $height, $visited);
                    if (count($contour) >= 4) { // Minimum 4 points for valid contour
                        $contours[] = $this->simplifyContour($contour);
                    }
                }
            }
        }

        return $contours;
    }

    /**
     * Check if position is a contour starting point
     */
    private function isContourStart(array $bitmap, int $x, int $y, int $width, int $height): bool
    {
        // Check if this cell has an edge (transition from inside to outside)
        $cell = $this->getCellValue($bitmap, $x, $y, $width, $height);
        $right = $this->getCellValue($bitmap, $x + 1, $y, $width, $height);
        $bottom = $this->getCellValue($bitmap, $x, $y + 1, $width, $height);

        return $cell && (!$right || !$bottom);
    }

    /**
     * Trace contour from starting point
     */
    private function traceContour(array $bitmap, int $startX, int $startY, int $width, int $height, array &$visited): array
    {
        $contour = [];
        $x = $startX;
        $y = $startY;
        $dir = 0; // Direction: 0=right, 1=down, 2=left, 3=up

        $maxSteps = ($width + $height) * 4; // Prevent infinite loop
        $steps = 0;

        do {
            $visited[$y][$x] = true;
            $contour[] = [$x, $y];

            // Find next position
            $found = false;
            for ($i = 0; $i < 4; $i++) {
                $testDir = ($dir + $i) % 4;
                [$nx, $ny] = $this->getNextPosition($x, $y, $testDir);

                if ($nx >= 0 && $nx < $width && $ny >= 0 && $ny < $height &&
                    $this->getCellValue($bitmap, $nx, $ny, $width, $height)) {
                    $x = $nx;
                    $y = $ny;
                    $dir = $testDir;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                break;
            }

            $steps++;
        } while (($x != $startX || $y != $startY) && $steps < $maxSteps);

        return $contour;
    }

    /**
     * Get next position based on direction
     */
    private function getNextPosition(int $x, int $y, int $dir): array
    {
        switch ($dir) {
            case 0: return [$x + 1, $y];     // right
            case 1: return [$x, $y + 1];     // down
            case 2: return [$x - 1, $y];     // left
            case 3: return [$x, $y - 1];     // up
        }
        return [$x, $y];
    }

    /**
     * Get cell value safely
     */
    private function getCellValue(array $bitmap, int $x, int $y, int $width, int $height): bool
    {
        if ($x < 0 || $x >= $width || $y < 0 || $y >= $height) {
            return false;
        }
        return !empty($bitmap[$y][$x]);
    }

    /**
     * Simplify contour using Douglas-Peucker algorithm
     */
    private function simplifyContour(array $contour, float $epsilon = 2.0): array
    {
        if (count($contour) < 3) {
            return $contour;
        }

        // Find the point with maximum distance
        $dmax = 0;
        $index = 0;
        $end = count($contour) - 1;

        for ($i = 1; $i < $end; $i++) {
            $d = $this->perpendicularDistance($contour[$i], $contour[0], $contour[$end]);
            if ($d > $dmax) {
                $index = $i;
                $dmax = $d;
            }
        }

        // If max distance is greater than epsilon, recursively simplify
        if ($dmax > $epsilon) {
            $recResults1 = $this->simplifyContour(array_slice($contour, 0, $index + 1), $epsilon);
            $recResults2 = $this->simplifyContour(array_slice($contour, $index), $epsilon);

            return array_merge(array_slice($recResults1, 0, -1), $recResults2);
        } else {
            return [$contour[0], $contour[$end]];
        }
    }

    /**
     * Calculate perpendicular distance from point to line
     */
    private function perpendicularDistance(array $point, array $lineStart, array $lineEnd): float
    {
        $dx = $lineEnd[0] - $lineStart[0];
        $dy = $lineEnd[1] - $lineStart[1];

        $mag = sqrt($dx * $dx + $dy * $dy);
        if ($mag == 0) {
            return sqrt(
                pow($point[0] - $lineStart[0], 2) +
                pow($point[1] - $lineStart[1], 2)
            );
        }

        $u = (($point[0] - $lineStart[0]) * $dx + ($point[1] - $lineStart[1]) * $dy) / ($mag * $mag);

        if ($u < 0 || $u > 1) {
            // Point is outside line segment
            $d1 = sqrt(pow($point[0] - $lineStart[0], 2) + pow($point[1] - $lineStart[1], 2));
            $d2 = sqrt(pow($point[0] - $lineEnd[0], 2) + pow($point[1] - $lineEnd[1], 2));
            return min($d1, $d2);
        }

        // Point on line
        $ix = $lineStart[0] + $u * $dx;
        $iy = $lineStart[1] + $u * $dy;

        return sqrt(pow($point[0] - $ix, 2) + pow($point[1] - $iy, 2));
    }
}
