<?php

namespace App\Services\Picking;

/**
 * Route optimization using Nearest Insertion and 2-opt algorithms
 * Based on specification: 20251111-optimize-picking-order2.md Section 3C
 */
class RouteOptimizer
{
    /**
     * Build initial route using Nearest Insertion algorithm
     * First key is fixed as start point, but we optimize the order of subsequent locations
     *
     * @param  array  $keys  Array of location keys (first key is START point)
     * @param  callable  $distFunc  Distance function (key1, key2) => distance
     * @return array Ordered array of keys
     */
    public function nearestInsertion(array $keys, callable $distFunc): array
    {
        if (count($keys) <= 1) {
            return $keys;
        }

        if (count($keys) === 2) {
            return $keys;
        }

        // Start point is fixed as first element
        $startKey = $keys[0];
        $unused = array_slice($keys, 1);

        // Find the nearest location to the start point
        $nearest = null;
        $nearestDist = null;
        foreach ($unused as $key) {
            $dist = $distFunc($startKey, $key);
            if ($nearest === null || $dist < $nearestDist) {
                $nearest = $key;
                $nearestDist = $dist;
            }
        }

        // Initialize route with start and nearest location
        $route = [$startKey, $nearest];
        $unused = array_values(array_diff($unused, [$nearest]));

        // Insert remaining locations using nearest insertion
        while (! empty($unused)) {
            $best = null;

            // For each unused location
            foreach ($unused as $unusedKey) {
                $bestGap = null;

                // Find the best position to insert this location (not before start point)
                for ($i = 1; $i < count($route); $i++) {
                    $a = $route[$i - 1];
                    $b = $route[$i];

                    // Calculate insertion cost
                    $delta = $distFunc($a, $unusedKey) + $distFunc($unusedKey, $b) - $distFunc($a, $b);

                    if ($bestGap === null || $delta < $bestGap[0]) {
                        $bestGap = [$delta, $i];
                    }
                }

                // Also consider appending at the end
                $lastKey = $route[count($route) - 1];
                $appendCost = $distFunc($lastKey, $unusedKey);
                if ($bestGap === null || $appendCost < $bestGap[0]) {
                    $bestGap = [$appendCost, count($route)];
                }

                // Track the overall best insertion
                if ($best === null || $bestGap[0] < $best[0]) {
                    $best = [$bestGap[0], $unusedKey, $bestGap[1]];
                }
            }

            // Insert the best location at the best position
            array_splice($route, $best[2], 0, [$best[1]]);
            $unused = array_values(array_diff($unused, [$best[1]]));
        }

        return $route;
    }

    /**
     * Improve route using 2-opt algorithm
     * First element (START point) is kept fixed
     *
     * @param  array  $seq  Ordered array of location keys
     * @param  callable  $distFunc  Distance function (key1, key2) => distance
     * @param  int  $minImprovement  Minimum improvement in pixels to accept a swap (default: 1)
     * @return array Improved route
     */
    public function twoOpt(array $seq, callable $distFunc, int $minImprovement = 1): array
    {
        $n = count($seq);
        if ($n <= 3) {
            return $seq; // Too small for 2-opt
        }

        $improved = true;
        $iterations = 0;
        $maxIterations = 1000; // Prevent infinite loops

        while ($improved && $iterations < $maxIterations) {
            $improved = false;
            $iterations++;

            // Start from index 1 to keep first element (START point) fixed
            for ($i = 2; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $a = $seq[$i - 1];
                    $b = $seq[$i];
                    $c = $seq[$k];
                    $d = $k + 1 < $n ? $seq[$k + 1] : null;

                    // Calculate the change in total distance
                    if ($d !== null) {
                        $currentDist = $distFunc($a, $b) + $distFunc($c, $d);
                        $newDist = $distFunc($a, $c) + $distFunc($b, $d);
                    } else {
                        // Last segment
                        $currentDist = $distFunc($a, $b) + $distFunc($b, $c);
                        $newDist = $distFunc($a, $c) + $distFunc($c, $b);
                    }
                    $delta = $currentDist - $newDist;

                    // If improvement is significant enough
                    if ($delta >= $minImprovement) {
                        // Reverse the segment between i and k
                        $seq = array_merge(
                            array_slice($seq, 0, $i),
                            array_reverse(array_slice($seq, $i, $k - $i + 1)),
                            array_slice($seq, $k + 1)
                        );
                        $improved = true;
                        break 2; // Restart the search
                    }
                }
            }
        }

        return $seq;
    }

    /**
     * Calculate total route distance
     *
     * @param  array  $route  Ordered array of location keys
     * @param  callable  $distFunc  Distance function
     * @return int Total distance in pixels
     */
    public function calculateTotalDistance(array $route, callable $distFunc): int
    {
        $total = 0;
        for ($i = 0; $i < count($route) - 1; $i++) {
            $total += $distFunc($route[$i], $route[$i + 1]);
        }

        return $total;
    }

    /**
     * Optimize route using both Nearest Insertion and 2-opt
     *
     * @param  array  $keys  Array of location keys
     * @param  callable  $distFunc  Distance function
     * @return array ['route' => array, 'distance' => int, 'iterations' => int]
     */
    public function optimizeRoute(array $keys, callable $distFunc): array
    {
        // Build initial route with Nearest Insertion
        $route = $this->nearestInsertion($keys, $distFunc);

        // Improve with 2-opt
        $route = $this->twoOpt($route, $distFunc);

        // Calculate total distance
        $distance = $this->calculateTotalDistance($route, $distFunc);

        return [
            'route' => $route,
            'distance' => $distance,
        ];
    }
}
