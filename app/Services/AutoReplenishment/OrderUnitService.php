<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Facades\DB;

/** One output unit per automatic policy; master permissions always apply. */
class OrderUnitService
{
    public function forEditor(int $client, object $item, string $channel, string $mode = 'AUTO', int $source = 0): array
    {
        $caseOutput = false;
        if ($channel === 'EOS') {
            $code = app(\App\Services\AutoOrder\OrderRegistrationSearchService::class)->orderingCodeForItem((int) $item->id);
            throw_unless($code, \RuntimeException::class, '発注用CDが未設定です。商品マスターを確認してください。');
            $product = new \App\Models\Sakemaru\Item;
            $product->setRawAttributes((array) $item);
            $probe = new \App\Models\WmsOrderCandidate(['order_quantity' => 1, 'quantity_type' => 'PIECE', 'ordering_code' => $code]);
            $probe->setRelation('item', $product);
            $caseOutput = app(\App\Services\AutoOrder\OrderOutputQuantityResolver::class)->resolve($probe)['ordering_unit_quantity'] !== null;
        }

        $history = $channel === 'LOGISTICS' && $source > 0
            ? (app(LogisticsOrderUnitHistory::class)->read($client, $source, [(int) $item->id => (int) $item->capacity_case])[$item->id] ?? null) : null;

        return $this->resolve($client, (int) $item->id, (int) $item->capacity_case, $caseOutput, mode: $mode, history: $history);
    }

    public function rows(int $client, array $items): array
    {
        return DB::connection('sakemaru')->table('item_quantity_information as q')
            ->join('items as i', 'i.id', '=', 'q.item_id')->where('i.client_id', $client)->where('i.is_active', 1)
            ->whereIn('q.item_id', $items)->where('q.can_order', 1)->where('q.quantity', '>', 0)
            ->get(['q.item_id', 'q.quantity'])->groupBy('item_id')->map(fn ($rows) => $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all())->all();
    }

    public function resolve(int $client, int $item, int $capacity, bool $caseOutput = false, ?array $rows = null, string $mode = 'AUTO', ?array $history = null): array
    {
        $quantities = ($rows ?? $this->rows($client, [$item]))[$item] ?? [];
        throw_unless($quantities && $capacity > 0, \RuntimeException::class,
            '商品の発注可能な入数またはケース入数が未設定です。商品マスターの「入数情報・発注区分」を確認してください。');
        $piece = in_array(1, $quantities, true) && ! $caseOutput;

        $automatic = ['piece_orderable' => $piece, 'quantity_type' => $piece ? 'PIECE' : 'CASE',
            'capacity_case' => $capacity, 'automatic_label' => $piece ? 'バラ発注' : 'ケース発注',
            'packing_unit' => $piece ? 1 : $capacity, 'label' => $piece ? 'バラ発注' : 'ケース発注',
            'reason' => $piece ? '商品マスターで1バラが発注可のため、バラで発注します。'
                : ($caseOutput ? '発注用CDの出力条件に従い、ケースで発注します。' : '商品マスターで1バラが発注不可のため、ケースで発注します。')];
        if ($history !== null) {
            $automatic['history'] = $history;
            $cases = (int) ($history['case_count'] ?? 0);
            $pieces = (int) ($history['piece_count'] ?? 0);
            if ($piece && $capacity > 1 && $cases + $pieces > 0) {
                $preferCase = $cases > $pieces;
                $automatic['quantity_type'] = $preferCase ? 'CASE' : 'PIECE';
                $automatic['packing_unit'] = $preferCase ? $capacity : 1;
                $automatic['label'] = $automatic['automatic_label'] = $preferCase ? 'ケース発注' : 'バラ発注';
                $automatic['reason'] = "移動元の実出庫：ケース単位 {$cases}回・端数あり {$pieces}回。".
                    ($preferCase ? 'ケース単位が多いため、ケースで発注します。' : 'バラで発注します（同数の場合もバラ）。');
            }
        }

        return $this->select($automatic, $mode);
    }

    public function select(array $automatic, string $mode): array
    {
        throw_unless(in_array($mode, ['AUTO', 'PIECE', 'CASE'], true), \RuntimeException::class, '発注単位は自動判定・バラ発注・ケース発注から選択してください。');
        throw_if($mode === 'PIECE' && ! $automatic['piece_orderable'], \RuntimeException::class,
            'この商品はバラ発注できません。発注単位を自動判定またはケース発注に変更してください。');
        $automatic['mode'] = $mode;
        if ($mode !== 'AUTO') {
            $automatic['quantity_type'] = $mode;
            $automatic['packing_unit'] = $mode === 'CASE' ? $automatic['capacity_case'] : 1;
            $automatic['label'] = $mode === 'CASE' ? 'ケース発注' : 'バラ発注';
            $automatic['reason'] = 'この倉庫の商品設定に従い、'.($mode === 'CASE' ? 'ケース' : 'バラ').'で発注します。';
        }

        return $automatic;
    }

    public function assertRecommendationMode(?array $basis, string $mode): void
    {
        if (isset($basis['scope']) && empty($basis['reason'])) {
            throw_if(($basis['scope']['order_unit_mode'] ?? 'AUTO') !== $mode, \RuntimeException::class,
                '発注単位が算定時から変わっています。「おすすめ設定」で計算してから保存してください。');
        }
    }
}
