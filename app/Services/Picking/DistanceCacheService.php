<?php

namespace App\Services\Picking;

use App\Models\WmsLayoutDistanceCache;
use App\Models\WmsWarehouseLayout;

/**
 * Distance caching service for picking route optimization
 * Based on specification: 20251111-optimize-picking-order2.md Section 3B
 */
class DistanceCacheService
{
    private AStarGrid $aStar;
    private int $warehouseId;
    private ?int $floorId;
    private string $layoutHash;

    /**
     * Calculate layout hash from layout data
     *
     * @param array $layoutData Layout data with width, height, walls, fixed_areas
     * @return string MD5 hash
     */
    public static function layoutHash(array $layoutData): string
    {
        // Normalize layout data for consistent hashing
        $normalized = [
            'width' => $layoutData['width'] ?? 0,
            'height' => $layoutData['height'] ?? 0,
            'walls' => $layoutData['walls'] ?? [],
            'fixed_areas' => $layoutData['fixed_areas'] ?? [],
        ];

        // Sort walls and fixed_areas to ensure consistent order
        usort($normalized['walls'], fn($a, $b) => ($a['x1'] ?? 0) <=> ($b['x1'] ?? 0));
        usort($normalized['fixed_areas'], fn($a, $b) => ($a['x1'] ?? 0) <=> ($b['x1'] ?? 0));

        return md5(json_encode($normalized));
    }

    /**
     * Initialize distance cache service
     *
     * @param AStarGrid $aStar A* algorithm instance
     * @param int $warehouseId Warehouse ID
     * @param int|null $floorId Floor ID
     * @param string $layoutHash Layout hash
     */
    public function __construct(
        AStarGrid $aStar,
        int $warehouseId,
        ?int $floorId,
        string $layoutHash
    ) {
        $this->aStar = $aStar;
        $this->warehouseId = $warehouseId;
        $this->floorId = $floorId;
        $this->layoutHash = $layoutHash;
    }

    /**
     * Get distance between two points (with caching)
     *
     * @param string $fromKey Key format: 'LOC:{id}' or 'NODE:START'
     * @param string $toKey Key format: 'LOC:{id}' or 'NODE:START'
     * @param callable $resolver Closure that resolves keys to [x, y] coordinates
     * @return array ['dist' => int, 'path' => [[x,y], ...]]
     */
    public function getDistance(string $fromKey, string $toKey, callable $resolver): array
    {
        // Check cache first
        $cached = WmsLayoutDistanceCache::where('warehouse_id', $this->warehouseId)
            ->where('floor_id', $this->floorId)
            ->where('layout_hash', $this->layoutHash)
            ->where('from_key', $fromKey)
            ->where('to_key', $toKey)
            ->first();

        if ($cached) {
            return [
                'dist' => $cached->meters,
                'path' => $cached->path_json ?? [],
            ];
        }

        // Cache miss - compute using A*
        $fromPoint = $resolver($fromKey);
        $toPoint = $resolver($toKey);

        $result = $this->aStar->shortest($fromPoint, $toPoint);

        $distance = $result['dist'];

        // Save to cache
        WmsLayoutDistanceCache::create([
            'warehouse_id' => $this->warehouseId,
            'floor_id' => $this->floorId,
            'layout_hash' => $this->layoutHash,
            'from_key' => $fromKey,
            'to_key' => $toKey,
            'meters' => $distance,
            'path_json' => $result['path'],
        ]);

        return [
            'dist' => $distance,
            'path' => $result['path'],
        ];
    }

    /**
     * Clear cache for a specific layout
     *
     * @param int $warehouseId Warehouse ID
     * @param int|null $floorId Floor ID
     * @param string|null $layoutHash Layout hash (null = clear all)
     * @return int Number of records deleted
     */
    public static function clearCache(int $warehouseId, ?int $floorId, ?string $layoutHash = null): int
    {
        $query = WmsLayoutDistanceCache::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId);

        if ($layoutHash !== null) {
            $query->where('layout_hash', $layoutHash);
        }

        return $query->delete();
    }

    /**
     * Get cache statistics
     *
     * @param int $warehouseId Warehouse ID
     * @param int|null $floorId Floor ID
     * @return array Statistics
     */
    public static function getCacheStats(int $warehouseId, ?int $floorId): array
    {
        $query = WmsLayoutDistanceCache::where('warehouse_id', $warehouseId)
            ->where('floor_id', $floorId);

        return [
            'total_entries' => $query->count(),
            'unique_layouts' => $query->distinct('layout_hash')->count('layout_hash'),
            'oldest_entry' => $query->min('created_at'),
            'newest_entry' => $query->max('created_at'),
        ];
    }
}