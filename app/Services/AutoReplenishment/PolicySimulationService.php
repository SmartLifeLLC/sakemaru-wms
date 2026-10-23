<?php

namespace App\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;

/** No run, candidate, history, queue or order writer is used by this service. */
class PolicySimulationService
{
    public function __construct(private SnapshotReader $reader, private ProposalSafety $safety, private LogisticsPickingDate $pickingDate) {}

    public function simulate(WmsItemReorderPolicy $policy): array
    {
        $snapshot = [];
        $formula = [];
        $picking = null;
        try {
            $snapshot = $this->reader->readForSimulation($policy);
            $strategyClass = config('replenishment.strategies.'.$policy->strategy_key);
            throw_unless(is_string($strategyClass) && class_exists($strategyClass), \RuntimeException::class, '計算ロジックが未設定です。管理者に商品設定を確認してください。');
            $strategy = app($strategyClass);
            $proposal = app(StrategyCalculator::class)->calculate($policy->toArray(), $snapshot);
            $this->safety->validate($policy->toArray(), $proposal, $snapshot['packing_unit'], $snapshot['shipping_unit'] ?? []);
            $formula = $proposal['formula'] ?? ($strategy instanceof ThresholdStrategy || $strategy instanceof CappedThresholdStrategy ? $this->thresholdFormula($policy, $snapshot, $proposal) : ['顧客別の計算ロジックによる判定：'.$proposal['reason']]);
            if (($snapshot['shipping_unit']['mode'] ?? '') === 'PALLET') {
                $formula[] = 'パレット単位：1パレット '.$snapshot['shipping_unit']['pieces_per_pallet'].'バラの倍数で発注します。';
            }
            if (($snapshot['shipping_unit']['mode'] ?? '') === 'SHIPMENT') {
                $formula[] = '登録済み出荷数量候補から選択しています。満載パレットへの切上げ・候補の合算は行いません。';
            }
            if ($snapshot['stock'] < 0) {
                $formula[] = '現在庫 '.$snapshot['stock'].'バラは負数のまま計算します。出荷予測で負数をさらに増やしません。';
            }
            if ($policy->channel === 'LOGISTICS') {
                $picking = $this->pickingDate->resolve($snapshot['arrival_date'], $snapshot['route_snapshot']['effective_picking_lead_days']);
            }
        } catch (\Throwable $e) {
            // Never expose SQL, credentials or internal error messages in the modal.
            $businessError = get_class($e) === \RuntimeException::class || get_class($e) === \InvalidArgumentException::class;
            if (! $businessError) {
                report($e);
            }
            $proposal = ['phase' => 'HOLD', 'quantity' => 0, 'reason' => $businessError ? $e->getMessage() : '仮計算に失敗しました。再実行しても解消しない場合は管理者に連絡してください。'];
            $snapshot = $snapshot ?: $this->reader->lastObservation();
        }

        $quantity = $proposal['phase'] === 'READY' ? $proposal['quantity'] : 0;
        $caseOrder = ($snapshot['quantity_type'] ?? '') === 'CASE';

        // Return only display data, not route fingerprints or full internal snapshots.
        return [
            'phase' => $proposal['phase'], 'reason' => $proposal['reason'],
            'quantity_pieces' => $quantity, 'calculated_pieces' => $proposal['quantity'],
            'order_quantity' => $caseOrder ? intdiv($quantity, $snapshot['capacity_case']) : $quantity,
            'quantity_type' => $caseOrder ? 'CASE' : 'PIECE',
            'order_unit' => $snapshot['order_unit'] ?? null,
            'master_warnings' => $snapshot['master_warnings'] ?? [],
            'stock' => $snapshot['stock'] ?? null, 'incoming' => $snapshot['incoming'] ?? null, 'claims' => $snapshot['claims'] ?? null,
            'incoming_transfer' => $snapshot['incoming_transfer'] ?? null, 'incoming_external' => $snapshot['incoming_external'] ?? null,
            'arrival_date' => $snapshot['arrival_date'] ?? null, 'picking_date' => $picking,
            'next_arrival_date' => $snapshot['next_arrival_date'] ?? null,
            'picking_lead_days' => $snapshot['route_snapshot']['effective_picking_lead_days'] ?? null,
            'business_date' => $snapshot['business_date'] ?? null, 'ordering_code' => $snapshot['ordering_code'] ?? null,
            'formula' => $formula, 'observed_at' => now()->format('Y/m/d H:i:s'), 'policy_version' => (int) $policy->version,
            'shipping_unit' => $snapshot['shipping_unit'] ?? ['mode' => 'STANDARD'],
            'quantity_settings' => $policy->strategy_key === 'arrival_adaptive_v1' ? [
                'normal' => $policy->calculation_basis['batch_quantity_pieces'] ?? null,
                'safety_days' => $policy->calculation_basis['safety_stock_days'] ?? null,
                'safety_pieces' => $policy->calculation_basis['safety_stock_pieces'] ?? null,
                'minimum' => (int) $policy->min_order_pieces, 'maximum' => (int) $policy->max_order_pieces,
                'target' => (int) ($policy->target_stock_pieces ?? $policy->reorder_point_pieces),
            ] : null,
        ];
    }

    private function thresholdFormula(WmsItemReorderPolicy $policy, array $s, array $p): array
    {
        $r = (int) $policy->reorder_point_pieces;
        $target = (int) ($policy->target_stock_pieces ?? $r);
        $supply = $s['stock'] + $s['incoming'] + $s['claims'];
        $steps = [
            "発注判定：現在庫 {$s['stock']} ".($s['stock'] < $r ? '＜' : '≧')." 発注点 {$r}",
            "補充後見込：現在庫 {$s['stock']} ＋ 未入荷残 {$s['incoming']} ＋ 未確定候補 {$s['claims']} ＝ {$supply}",
            "不足数：補充目標 {$target} − 補充後見込 {$supply} ＝ ".($target - $supply),
        ];
        if (isset($s['incoming_transfer'], $s['incoming_external'])) {
            array_splice($steps, 2, 0, ["未入荷残の内訳：外部発注 {$s['incoming_external']} ＋ 倉庫移動 {$s['incoming_transfer']} ＝ {$s['incoming']}（同じ供給は重複除外済み）"]);
        }
        if (isset($p['unit'], $p['shortage'])) {
            $min = (int) $policy->min_order_pieces;
            $need = max($min, $p['shortage']);
            $steps[] = "必要数：大きい方（不足数 {$p['shortage']}、最小発注数 {$min}）＝ {$need}";
            $steps[] = "発注単位：最小公倍数（最小発注単位 {$policy->order_multiple_pieces}、出力単位 {$s['packing_unit']}）＝ {$p['unit']}";
            $required = $p['required_quantity'] ?? $p['quantity'];
            $steps[] = "単位切上げ：{$need} ÷ {$p['unit']} を切上げ × {$p['unit']} ＝ {$required}";
            $steps[] = "上限判定：必要数量 {$required} ".($required <= $policy->max_order_pieces ? '≦' : '＞')." 最大発注数 {$policy->max_order_pieces}";
            if ($p['capped'] ?? false) {
                $steps[] = "今回分：最大発注数 {$policy->max_order_pieces} 以下で単位 {$p['unit']} に合う {$p['quantity']}バラ。未入荷・既発注を含めて次回再判定します。";
            }
        }
        $steps[] = $p['phase'] === 'READY' ? "仮発注数量：{$p['quantity']}バラ" : '発注データは生成対象外：'.$p['reason'];

        return $steps;
    }
}
