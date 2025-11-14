<?php

namespace App\Services\Picking;

/**
 * A* pathfinding algorithm on a grid
 * Based on specification: 20251111-optimize-picking-order2.md
 */
class AStarGrid
{
    private int $cellSize;
    private array $blockedRects;
    private int $gridWidth;
    private int $gridHeight;

    /**
     * @param int $cellSize Grid cell size in pixels (e.g., 25 or 50)
     * @param array $blockedRects Array of blocked rectangles [[x1,y1,x2,y2], ...]
     * @param int $canvasWidth Canvas width in pixels
     * @param int $canvasHeight Canvas height in pixels
     */
    public function __construct(
        int $cellSize,
        array $blockedRects,
        int $canvasWidth,
        int $canvasHeight
    ) {
        $this->cellSize = $cellSize;
        $this->blockedRects = $blockedRects;
        $this->gridWidth = (int) ceil($canvasWidth / $cellSize);
        $this->gridHeight = (int) ceil($canvasHeight / $cellSize);
    }

    /**
     * Find shortest path between two points using A*
     *
     * @param array $start [x, y] in pixels
     * @param array $goal [x, y] in pixels
     * @return array ['dist' => int, 'path' => [[x,y], ...]]
     */
    public function shortest(array $start, array $goal): array
    {
        // Convert pixel coordinates to cell coordinates
        $startCell = $this->pixelToCell($start);
        $goalCell = $this->pixelToCell($goal);

        // Check if start or goal is blocked
        if ($this->isBlockedCell($startCell[0], $startCell[1]) ||
            $this->isBlockedCell($goalCell[0], $goalCell[1])) {
            return ['dist' => PHP_INT_MAX, 'path' => []];
        }

        // A* implementation
        $openSet = new \SplPriorityQueue();
        $openSet->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);

        $cameFrom = [];
        $gScore = [];
        $fScore = [];

        $startKey = $this->cellKey($startCell);
        $goalKey = $this->cellKey($goalCell);

        $gScore[$startKey] = 0;
        $fScore[$startKey] = $this->heuristic($startCell, $goalCell);
        $openSet->insert($startKey, -$fScore[$startKey]);

        $visited = [];

        while (!$openSet->isEmpty()) {
            $current = $openSet->extract();
            $currentKey = $current['data'];

            if ($currentKey === $goalKey) {
                // Reconstruct path
                $path = $this->reconstructPath($cameFrom, $currentKey, $startKey);
                $dist = $gScore[$currentKey];
                return ['dist' => $dist, 'path' => $path];
            }

            if (isset($visited[$currentKey])) {
                continue;
            }
            $visited[$currentKey] = true;

            $currentCell = $this->keyToCell($currentKey);

            // Check all neighbors (4-directional)
            $neighbors = $this->getNeighbors($currentCell);

            foreach ($neighbors as $neighbor) {
                $neighborKey = $this->cellKey($neighbor);

                if (isset($visited[$neighborKey]) || $this->isBlockedCell($neighbor[0], $neighbor[1])) {
                    continue;
                }

                $tentativeGScore = $gScore[$currentKey] + $this->cellSize;

                if (!isset($gScore[$neighborKey]) || $tentativeGScore < $gScore[$neighborKey]) {
                    $cameFrom[$neighborKey] = $currentKey;
                    $gScore[$neighborKey] = $tentativeGScore;
                    $fScore[$neighborKey] = $tentativeGScore + $this->heuristic($neighbor, $goalCell);
                    $openSet->insert($neighborKey, -$fScore[$neighborKey]);
                }
            }
        }

        // No path found
        return ['dist' => PHP_INT_MAX, 'path' => []];
    }

    /**
     * Check if a cell is blocked by any obstacle
     */
    public function isBlockedCell(int $cx, int $cy): bool
    {
        // Check bounds
        if ($cx < 0 || $cx >= $this->gridWidth || $cy < 0 || $cy >= $this->gridHeight) {
            return true;
        }

        // Convert cell to pixel rectangle
        $cellX1 = $cx * $this->cellSize;
        $cellY1 = $cy * $this->cellSize;
        $cellX2 = $cellX1 + $this->cellSize;
        $cellY2 = $cellY1 + $this->cellSize;

        // Check if cell overlaps with any blocked rectangle
        foreach ($this->blockedRects as $rect) {
            if ($this->rectanglesOverlap(
                $cellX1, $cellY1, $cellX2, $cellY2,
                $rect['x1'], $rect['y1'], $rect['x2'], $rect['y2']
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if two rectangles overlap
     */
    private function rectanglesOverlap(
        int $ax1, int $ay1, int $ax2, int $ay2,
        int $bx1, int $by1, int $bx2, int $by2
    ): bool {
        return !($ax2 <= $bx1 || $bx2 <= $ax1 || $ay2 <= $by1 || $by2 <= $ay1);
    }

    /**
     * Convert pixel coordinates to cell coordinates
     */
    private function pixelToCell(array $pixel): array
    {
        return [
            (int) floor($pixel[0] / $this->cellSize),
            (int) floor($pixel[1] / $this->cellSize),
        ];
    }

    /**
     * Convert cell coordinates to pixel coordinates (center of cell)
     */
    private function cellToPixel(array $cell): array
    {
        return [
            $cell[0] * $this->cellSize + (int) ($this->cellSize / 2),
            $cell[1] * $this->cellSize + (int) ($this->cellSize / 2),
        ];
    }

    /**
     * Generate a unique key for a cell
     */
    private function cellKey(array $cell): string
    {
        return "{$cell[0]},{$cell[1]}";
    }

    /**
     * Convert key back to cell coordinates
     */
    private function keyToCell(string $key): array
    {
        $parts = explode(',', $key);
        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * Manhattan distance heuristic
     */
    private function heuristic(array $a, array $b): int
    {
        return abs($a[0] - $b[0]) * $this->cellSize + abs($a[1] - $b[1]) * $this->cellSize;
    }

    /**
     * Get neighboring cells (4-directional)
     */
    private function getNeighbors(array $cell): array
    {
        return [
            [$cell[0] + 1, $cell[1]],     // Right
            [$cell[0] - 1, $cell[1]],     // Left
            [$cell[0], $cell[1] + 1],     // Down
            [$cell[0], $cell[1] - 1],     // Up
        ];
    }

    /**
     * Reconstruct path from A* search
     */
    private function reconstructPath(array $cameFrom, string $current, string $start): array
    {
        $path = [];
        $pathCells = [];

        while ($current !== $start) {
            $pathCells[] = $this->keyToCell($current);
            $current = $cameFrom[$current] ?? $start;
        }
        $pathCells[] = $this->keyToCell($start);

        // Reverse to get start -> goal order
        $pathCells = array_reverse($pathCells);

        // Convert cells to pixel coordinates
        foreach ($pathCells as $cell) {
            $path[] = $this->cellToPixel($cell);
        }

        return $path;
    }
}