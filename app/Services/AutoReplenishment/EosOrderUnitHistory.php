<?php

namespace App\Services\AutoReplenishment;

use App\Services\AutoOrder\OrderTransmissionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Sent orders of this store, product and contractor only; never pending candidates or receipt quantities. */
class EosOrderUnitHistory
{
    public const MIN_ORDERS = 5;

    public const DOMINANT_PERCENT = 80;

    public function read(int $client, int $warehouse, int $item, int $contractor, string $orderingCode, ?string $asOf = null): array
    {
        $db = DB::connection('sakemaru');
        $asOf ??= $db->table('client_settings')->where('client_id', $client)->value('system_date');
        throw_unless($asOf, \RuntimeException::class, '業務日が未設定です。');
        $asOf = min(CarbonImmutable::now('Asia/Tokyo')->toDateString(), CarbonImmutable::parse($asOf)->toDateString());
        $period = ['start' => CarbonImmutable::parse($asOf)->subDays(90)->toDateString(),
            'end' => CarbonImmutable::parse($asOf)->subDay()->toDateString()];
        $mapping = app(OrderTransmissionService::class)->getGenerator()?->getTransmissionContractorMapping() ?? [];
        $transmissionContractor = (int) ($mapping[$contractor] ?? $contractor);
        // A document may contain several stores. Scope by the candidate, not the document header's warehouse.
        // The header uses the consolidated JX destination; keep the original contractor filter on the candidate.
        // No join to transmission logs or incoming splits: retries and split receipts must not multiply observations.
        $counts = $db->table('wms_order_candidates as c')
            ->join('wms_order_jx_documents as d', 'd.id', '=', 'c.wms_order_jx_document_id')
            ->join('items as i', 'i.id', '=', 'c.item_id')
            ->join('warehouses as w', 'w.id', '=', 'c.warehouse_id')
            ->where(['i.client_id' => $client, 'i.is_active' => 1, 'w.client_id' => $client, 'w.is_active' => 1,
                'c.warehouse_id' => $warehouse, 'c.item_id' => $item, 'c.contractor_id' => $contractor,
                'c.ordering_code' => $orderingCode, 'c.status' => 'EXECUTED'])
            ->whereIn('d.status', ['TRANSMITTED', 'CONFIRMED'])
            ->where('d.contractor_id', $transmissionContractor)
            ->where(fn ($q) => $q->whereNull('c.order_channel')->orWhere('c.order_channel', 'EOS'))
            ->where('c.order_quantity', '>', 0)->whereIn('c.quantity_type', ['CASE', 'PIECE'])
            ->whereBetween('d.order_date', array_values($period))
            ->whereNotNull('d.transmitted_at')->where('d.transmitted_at', '<', $asOf.' 00:00:00')
            ->groupBy('c.quantity_type')->select('c.quantity_type')
            ->selectRaw('COUNT(DISTINCT c.id) count_orders')->pluck('count_orders', 'quantity_type');

        return $this->summarize((int) ($counts['CASE'] ?? 0), (int) ($counts['PIECE'] ?? 0)) + [
            'source' => 'eos_transmitted_orders', 'warehouse_id' => $warehouse, 'item_id' => $item,
            'contractor_id' => $contractor, 'ordering_code' => $orderingCode, 'period' => $period,
            'transmission_contractor_id' => $transmissionContractor,
        ];
    }

    public function summarize(int $cases, int $pieces): array
    {
        $total = $cases + $pieces;
        $type = null;
        if ($total >= self::MIN_ORDERS) {
            if ($cases * 100 >= $total * self::DOMINANT_PERCENT) {
                $type = 'CASE';
            } elseif ($pieces * 100 >= $total * self::DOMINANT_PERCENT) {
                $type = 'PIECE';
            }
        }

        return ['case_count' => $cases, 'piece_count' => $pieces, 'count_orders' => $total,
            'preferred_type' => $type, 'minimum_orders' => self::MIN_ORDERS, 'dominant_percent' => self::DOMINANT_PERCENT,
            'decision' => $type ? 'history' : ($total < self::MIN_ORDERS ? 'insufficient_history' : 'mixed_history')];
    }
}
