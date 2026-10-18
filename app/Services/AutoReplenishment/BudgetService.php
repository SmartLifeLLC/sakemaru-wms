<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRunLine;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /** Retain execution accounting for reconciliation; quantity caps belong to each item policy. */
    public function reserve(WmsAutoOrderRunLine $line, string $amount): void
    {
        $db = DB::connection('sakemaru');
        $existing = $db->table('wms_auto_order_budget_entries')->where('effect_key', $line->effect_key)->first();
        if ($existing) {
            throw_if($existing->state === 'RELEASED', \RuntimeException::class, '中止済みの発注は再実行できません。');

            return;
        }
        $db->table('wms_auto_order_budget_entries')->insert([
            'effect_key' => $line->effect_key, 'client_id' => $line->client_id, 'warehouse_id' => $line->warehouse_id,
            'item_id' => $line->item_id, 'quantity_pieces' => $line->quantity_pieces, 'amount' => $amount,
            'state' => 'RESERVED', 'authorized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line->update(['amount' => $amount]);
    }

    public function commit(WmsAutoOrderRunLine $line): void
    {
        DB::connection('sakemaru')->table('wms_auto_order_budget_entries')->where('effect_key', $line->effect_key)
            ->where('state', 'RESERVED')->update(['state' => 'COMMITTED', 'committed_at' => now(), 'updated_at' => now()]);
    }

    public function markTransmissionState(int $documentId, string $state): void
    {
        if (! in_array($state, ['TRANSMITTING', 'ERROR', 'TRANSMITTED'], true) || ! app(ReplenishmentGuard::class)->installed()) {
            return;
        }
        $db = DB::connection('sakemaru');
        $lines = $db->table('wms_auto_order_artifact_links as a')->join('wms_auto_order_run_lines as l', 'l.id', '=', 'a.line_id')
            ->where('a.artifact_type', 'jx')->where('a.artifact_id', $documentId)->get(['l.client_id', 'l.effect_key']);
        if ($lines->isEmpty()) {
            return;
        }
        $db->transaction(function () use ($db, $lines, $state) {
            foreach ($lines->pluck('client_id')->unique()->sort() as $clientId) {
                $db->table('wms_auto_order_controls')->where('client_id', $clientId)->lockForUpdate()->first();
            }
            $q = $db->table('wms_auto_order_budget_entries')->whereIn('effect_key', $lines->pluck('effect_key'));
            if ($state === 'TRANSMITTED') {
                $q->where('state', 'UNKNOWN')->update(['state' => 'COMMITTED', 'committed_at' => now(), 'updated_at' => now()]);
            } else {
                $q->where('state', 'COMMITTED')->update(['state' => 'UNKNOWN', 'updated_at' => now()]);
            }
        }, 3);
    }
}
