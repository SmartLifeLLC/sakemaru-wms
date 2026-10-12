<?php

namespace App\Services\AutoOrder;

use App\Models\WmsOrderIncomingSchedule;
use Illuminate\Support\Facades\DB;

class IncomingPurchaseComparisonReader
{
    /** The same direct queue and uniqueness checks as the Core list. */
    public function read(WmsOrderIncomingSchedule $schedule): ?object
    {
        if (config('wms.client') !== 'hana' || ! $schedule->purchase_queue_id
            || in_array($schedule->status?->value ?? $schedule->status, ['DELETED', 'CANCELLED'], true)) {
            return null;
        }
        $db = DB::connection('sakemaru');
        $clientId = (int) config('client.id', config('app.client_id'));
        $queue = $db->table('purchase_create_queue')->where('id', $schedule->purchase_queue_id)
            ->where('status', 'FINISHED')->where('is_success', true)->first();
        if (! $queue || ! ctype_digit((string) $queue->slip_number)) {
            return null;
        }
        $serials = data_get(json_decode((string) ($queue->items ?? ''), true), 'created_purchase_serial_ids', []);
        $serials = is_array($serials) ? $serials : [];
        $trades = $db->table('trades')->where('client_id', $clientId)->where('trade_category', 'PURCHASE')
            ->where('is_latest', true)->whereIn('serial_id', count($serials) > 1 ? $serials : [$queue->slip_number])
            ->whereExists(function ($query) use ($schedule, $clientId): void {
                $query->selectRaw('1')->from('trade_items')->whereColumn('trade_items.trade_id', 'trades.id')
                    ->where('trade_items.client_id', $clientId)->where('trade_items.item_id', $schedule->item_id)
                    ->where('trade_items.is_active', true);
            })->get();
        if ($trades->count() !== 1 || ! $trades[0]->is_active || $trades[0]->is_offset) {
            return null;
        }
        $purchases = $db->table('purchases')->where('trade_id', $trades[0]->id)
            ->where('client_id', $clientId)->where('warehouse_id', $schedule->warehouse_id)->get();
        if ($purchases->count() !== 1 || ! $purchases[0]->is_active) {
            return null;
        }
        $lines = $db->table('trade_items')->where('trade_id', $trades[0]->id)->where('client_id', $clientId)
            ->where('item_id', $schedule->item_id)->where('is_active', true)->get();
        if ($lines->count() !== 1 || $lines[0]->quantity <= 0
            || ($lines[0]->total_piece_quantity !== null && $lines[0]->total_piece_quantity <= 0)
            || $db->table('wms_order_incoming_schedules')
            ->where('purchase_queue_id', $queue->id)->where('item_id', $schedule->item_id)->count() !== 1) {
            return null;
        }
        return $db->table('wms_incoming_price_check_sources')
            ->where('source_key', hash('sha256', 'PURCHASE_SLIP_REGISTERED|source|trade_item:'.$lines[0]->id))
            ->where('source_type', 'PURCHASE_SLIP_REGISTERED')->where('source_schema_version', '2026-10-12')->first();
    }
}
