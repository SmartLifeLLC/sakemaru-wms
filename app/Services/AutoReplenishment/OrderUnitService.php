<?php

namespace App\Services\AutoReplenishment;

use Illuminate\Support\Facades\DB;

/** Automatic orders use one unit, determined by the current product master. */
class OrderUnitService
{
    public function forEditor(int $client, object $item, string $channel): array
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

        return $this->resolve($client, (int) $item->id, (int) $item->capacity_case, $caseOutput);
    }

    public function rows(int $client, array $items): array
    {
        return DB::connection('sakemaru')->table('item_quantity_information as q')
            ->join('items as i', 'i.id', '=', 'q.item_id')->where('i.client_id', $client)->where('i.is_active', 1)
            ->whereIn('q.item_id', $items)->where('q.can_order', 1)->where('q.quantity', '>', 0)
            ->get(['q.item_id', 'q.quantity'])->groupBy('item_id')->map(fn ($rows) => $rows->pluck('quantity')->map(fn ($q) => (int) $q)->all())->all();
    }

    public function resolve(int $client, int $item, int $capacity, bool $caseOutput = false, ?array $rows = null): array
    {
        $quantities = ($rows ?? $this->rows($client, [$item]))[$item] ?? [];
        throw_unless($quantities && $capacity > 0, \RuntimeException::class,
            '商品の発注可能な入数またはケース入数が未設定です。商品マスターの「入数情報・発注区分」を確認してください。');
        $piece = in_array(1, $quantities, true) && ! $caseOutput;

        return ['piece_orderable' => $piece, 'quantity_type' => $piece ? 'PIECE' : 'CASE',
            'packing_unit' => $piece ? 1 : $capacity, 'label' => $piece ? 'バラ発注' : 'ケース発注',
            'reason' => $piece ? '商品マスターで1バラが発注可のため、バラで発注します。'
                : ($caseOutput ? '発注用CDの出力条件に従い、ケースで発注します。' : '商品マスターで1バラが発注不可のため、ケースで発注します。')];
    }
}
