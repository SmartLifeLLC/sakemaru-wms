<?php

namespace App\Services\Picking;

/**
 * Convert bitmap to minimal set of rectangles
 * Uses greedy algorithm to find largest rectangles
 */
class BitmapToRectangles
{
    /**
     * Convert bitmap to array of rectangles
     *
     * @param  array  $bitmap  2D boolean array
     * @param  int  $cellSize  Size of each cell in pixels
     * @return array Array of rectangles [['x1' => int, 'y1' => int, 'x2' => int, 'y2' => int], ...]
     */
    public function convert(array $bitmap, int $cellSize): array
    {
        if (empty($bitmap) || empty($bitmap[0])) {
            return [];
        }

        $height = count($bitmap);
        $width = count($bitmap[0]);

        // Create a working copy
        $grid = array_map(fn ($row) => [...$row], $bitmap);

        $rectangles = [];

        // Greedy algorithm: find largest rectangles
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($grid[$y][$x]) {
                    // Found a starting point, try to expand into largest rectangle
                    $rect = $this->findLargestRectangle($grid, $x, $y, $width, $height);

                    if ($rect) {
                        // Mark cells as used
                        for ($ry = $rect['y1']; $ry <= $rect['y2']; $ry++) {
                            for ($rx = $rect['x1']; $rx <= $rect['x2']; $rx++) {
                                $grid[$ry][$rx] = false;
                            }
                        }

                        // Convert to pixel coordinates
                        $rectangles[] = [
                            'x1' => $rect['x1'] * $cellSize,
                            'y1' => $rect['y1'] * $cellSize,
                            'x2' => ($rect['x2'] + 1) * $cellSize,
                            'y2' => ($rect['y2'] + 1) * $cellSize,
                        ];
                    }
                }
            }
        }

        return $rectangles;
    }

    /**
     * Find largest rectangle starting from (x, y)
     *
     * @param  array  $grid  Working grid
     * @param  int  $startX  Starting X coordinate
     * @param  int  $startY  Starting Y coordinate
     * @param  int  $width  Grid width
     * @param  int  $height  Grid height
     * @return array|null Rectangle coordinates or null
     */
    private function findLargestRectangle(array $grid, int $startX, int $startY, int $width, int $height): ?array
    {
        // Find maximum width at starting row
        $maxWidth = 0;
        for ($x = $startX; $x < $width && $grid[$startY][$x]; $x++) {
            $maxWidth++;
        }

        if ($maxWidth === 0) {
            return null;
        }

        // Try to expand vertically while maintaining the width
        $bestRect = null;
        $bestArea = 0;

        for ($currentWidth = $maxWidth; $currentWidth > 0; $currentWidth--) {
            // Find how far we can extend vertically with this width
            $maxHeight = 0;
            for ($y = $startY; $y < $height; $y++) {
                // Check if all cells in this row are available
                $rowOk = true;
                for ($x = $startX; $x < $startX + $currentWidth; $x++) {
                    if (! $grid[$y][$x]) {
                        $rowOk = false;
                        break;
                    }
                }

                if ($rowOk) {
                    $maxHeight++;
                } else {
                    break;
                }
            }

            $area = $currentWidth * $maxHeight;
            if ($area > $bestArea) {
                $bestArea = $area;
                $bestRect = [
                    'x1' => $startX,
                    'y1' => $startY,
                    'x2' => $startX + $currentWidth - 1,
                    'y2' => $startY + $maxHeight - 1,
                ];
            }
        }

        return $bestRect;
    }

    /**
     * Convert rectangles back to bitmap
     *
     * @param  array  $rectangles  Array of rectangles
     * @param  int  $cellSize  Size of each cell in pixels
     * @param  int  $canvasWidth  Canvas width in pixels
     * @param  int  $canvasHeight  Canvas height in pixels
     * @return array 2D boolean array
     */
    public function rectanglesToBitmap(array $rectangles, int $cellSize, int $canvasWidth, int $canvasHeight): array
    {
        $bitmapWidth = (int) ceil($canvasWidth / $cellSize);
        $bitmapHeight = (int) ceil($canvasHeight / $cellSize);

        // Initialize empty bitmap
        $bitmap = array_fill(0, $bitmapHeight, array_fill(0, $bitmapWidth, false));

        // Fill rectangles
        foreach ($rectangles as $rect) {
            // Convert pixel coordinates to cell coordinates
            $x1 = (int) floor($rect['x1'] / $cellSize);
            $y1 = (int) floor($rect['y1'] / $cellSize);
            $x2 = (int) floor($rect['x2'] / $cellSize);
            $y2 = (int) floor($rect['y2'] / $cellSize);

            // Fill cells
            for ($y = $y1; $y < $y2 && $y < $bitmapHeight; $y++) {
                for ($x = $x1; $x < $x2 && $x < $bitmapWidth; $x++) {
                    $bitmap[$y][$x] = true;
                }
            }
        }

        return $bitmap;
    }
}
