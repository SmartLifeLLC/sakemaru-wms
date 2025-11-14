<?php

namespace App\Services\Picking;

use App\Models\Sakemaru\Location;

/**
 * Calculate front points for locations
 * Based on specification: 20251111-optimize-picking-order2.md Section 2
 */
class FrontPointCalculator
{
    private int $delta;

    /**
     * @param int $delta Pixels to shift towards aisle (default: 10)
     */
    public function __construct(int $delta = 10)
    {
        $this->delta = $delta;
    }

    /**
     * Compute front point for a location
     * Returns the aisle-side center point of the location rectangle
     *
     * @param Location|array $location Location model or array with x1_pos, y1_pos, x2_pos, y2_pos
     * @return array [x, y] coordinates
     */
    public function computeFrontPoint($location): array
    {
        if ($location instanceof Location) {
            $x1 = $location->x1_pos;
            $y1 = $location->y1_pos;
            $x2 = $location->x2_pos;
            $y2 = $location->y2_pos;
        } else {
            $x1 = $location['x1_pos'] ?? $location['x1'];
            $y1 = $location['y1_pos'] ?? $location['y1'];
            $x2 = $location['x2_pos'] ?? $location['x2'];
            $y2 = $location['y2_pos'] ?? $location['y2'];
        }

        $width = abs($x2 - $x1);
        $height = abs($y2 - $y1);

        // If width >= height, longer side is horizontal
        // -> front is on top side (y=min)
        if ($width >= $height) {
            $x = intdiv($x1 + $x2, 2);
            $y = min($y1, $y2) - $this->delta;
        } else {
            // Longer side is vertical
            // -> front is on left side (x=min)
            $x = min($x1, $x2) - $this->delta;
            $y = intdiv($y1 + $y2, 2);
        }

        return [$x, $y];
    }

    /**
     * Compute front points for multiple locations
     *
     * @param array $locations Array of Location models or arrays
     * @return array Associative array [location_id => [x, y]]
     */
    public function computeFrontPoints(array $locations): array
    {
        $frontPoints = [];

        foreach ($locations as $location) {
            $id = $location instanceof Location ? $location->id : ($location['id'] ?? null);
            if ($id) {
                $frontPoints[$id] = $this->computeFrontPoint($location);
            }
        }

        return $frontPoints;
    }
}