<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Models\WmsItemDemandEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only access to approved events and learned factors for the recommendation. */
class DemandEventRepository
{
    private static ?bool $installed = null;

    private array $cache = [];

    /** Approved events as calendar rows; empty when the feature tables are not deployed yet. */
    public function approvedEvents(int $client): array
    {
        return $this->cache['events:'.$client] ??= $this->available()
            ? WmsDemandEvent::where(['client_id' => $client, 'status' => 'APPROVED'])->orderBy('start_date')->get()->map->toCalendarEvent()->all()
            : [];
    }

    /** Event types enabled for one product (empty = no event applies to it). */
    public function itemEventTypes(int $client, int $itemId): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->cache['item_events:'.$itemId] ??= WmsItemDemandEvent::where(['client_id' => $client, 'item_id' => $itemId, 'is_enabled' => true])
            ->pluck('event_type')->map(fn ($t) => (string) $t)->all();
    }

    /** Approved events that apply to one product. */
    public function eventsFor(int $client, int $itemId): array
    {
        $types = $this->itemEventTypes($client, $itemId);

        return array_values(array_filter($this->approvedEvents($client), fn ($e) => in_array($e['type'], $types, true)));
    }

    /**
     * ['item' => [key => profile], 'category' => [key => profile]] for one product. The category profile is a
     * suggestion only unless config replenishment.demand_events.category_fallback is on.
     */
    public function factorsFor(int $client, int $itemId, bool $includeCategory = false): array
    {
        if (! $this->available()) {
            return ['item' => [], 'category' => []];
        }
        $includeCategory = $includeCategory || (bool) config('replenishment.demand_events.category_fallback', false);
        $category = $this->cache['category:'.$itemId] ??= DB::connection('sakemaru')->table('items')->where(['client_id' => $client, 'id' => $itemId])->value('item_category3_id');
        $rows = WmsDemandEventProfile::where('client_id', $client)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('scope_type', 'ITEM')->where('scope_id', $itemId))
                ->when($category, fn ($q) => $q->orWhere(fn ($q) => $q->where('scope_type', 'CATEGORY')->where('scope_id', (int) $category))))
            ->get();
        $factors = ['item' => [], 'category' => []];
        foreach ($rows as $row) {
            if ($row->scope_type !== 'ITEM' && ! $includeCategory) {
                continue;
            }
            $factors[$row->scope_type === 'ITEM' ? 'item' : 'category'][$row->profile_key] = ['pieces' => (float) $row->pieces,
                'positions' => is_array($row->positions) ? $row->positions : [], 'instance_count' => (int) $row->instance_count,
                'trained_through' => $row->trained_through?->toDateString()];
        }

        return $factors;
    }

    /** Calculator option, or null when no approved event applies to the product (keeps the weekday-only behaviour). */
    public function recommendationOptions(int $client, int $itemId): ?array
    {
        $events = $this->eventsFor($client, $itemId);
        if (! $events) {
            return null;
        }

        return ['events' => $events, 'factors' => $this->factorsFor($client, $itemId)];
    }

    private function available(): bool
    {
        return self::$installed ??= Schema::connection('sakemaru')->hasTable('wms_demand_events')
            && Schema::connection('sakemaru')->hasTable('wms_demand_event_profiles') && Schema::connection('sakemaru')->hasTable('wms_item_demand_events');
    }

    /** Tests swap the sakemaru connection; production never needs this. */
    public static function resetInstalledFlag(): void
    {
        self::$installed = null;
    }
}
