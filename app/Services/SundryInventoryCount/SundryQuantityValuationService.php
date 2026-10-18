<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryValuationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Nonmanaged appraisal only. Never changes stock quantities or managed count rows. */
class SundryQuantityValuationService
{
    public static function available(): bool
    {
        return Schema::connection('sakemaru')->hasTable('wms_sundry_valuation_items')
            && Schema::connection('sakemaru')->hasTable(SundryAmountAdjustmentService::TABLE);
    }

    public static function categoryUsesQuantities(int $amountId): bool
    {
        return self::available() && WmsSundryValuationItem::where('amount_id', $amountId)->exists();
    }

    public function prepare(WmsSundryInventoryCount $count, int $amountId, bool $refreshUnenteredPrices = false): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($count, $amountId, $refreshUnenteredPrices): int {
            $count = $this->lockEditable($count);
            $amount = $count->amounts()->whereKey($amountId)->firstOrFail();
            // Repeated requests never replace snapshots, quantities or a manual appraisal.
            $existing = WmsSundryValuationItem::where('amount_id', $amountId)->get()->keyBy('item_id');
            if ($existing->isNotEmpty() && ! $refreshUnenteredPrices) {
                return 0;
            }
            if ($existing->isEmpty() && $amount->counted_amount !== null) {
                $this->fail('入力済みの実棚金額があります。数量入力への切替えはできません。既存の金額は保持されています。');
            }

            $db = DB::connection('sakemaru');
            $items = $db->table('items as i')
                ->leftJoin('item_categories as c1', 'c1.id', '=', 'i.item_category1_id')
                ->leftJoin('item_categories as c2', 'c2.id', '=', 'i.item_category2_id')
                ->leftJoin('item_categories as c3', 'c3.id', '=', 'i.item_category3_id')
                ->where('i.client_id', $count->client_id)
                ->where('i.item_category2_id', $amount->category2_id)
                ->where('i.is_managed_stock', false)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_sets as s')
                    ->whereColumn('s.id', 'i.item_set_id')->where('s.set_type', 'OWNED')->where('s.is_active', true))
                ->orderBy('i.code')->get(['i.id', 'i.code', 'i.name',
                    'c1.id as c1_id', 'c2.id as c2_id', 'c3.id as c3_id',
                    'c1.cost_rate as rate1', 'c2.cost_rate as rate2', 'c3.cost_rate as rate3']);
            if ($items->isEmpty()) {
                $this->fail('この中分類には在庫管理なしの商品がありません。');
            }
            $date = $count->count_date->toDateString();
            $prices = $db->table('item_prices')->where('client_id', $count->client_id)
                ->whereIn('item_id', $items->pluck('id'))->where('is_active', true)
                ->where('start_date', '<=', $date)->orderByDesc('start_date')->orderByDesc('id')
                ->get(['id', 'item_id', 'start_date', 'sale_unit_price', 'type', 'tax_exempt_unit_price'])
                ->unique('item_id')->keyBy('item_id');

            $saved = 0;
            foreach ($items as $item) {
                $row = $existing->get($item->id);
                if ($row?->quantity !== null || ($existing->isNotEmpty() && ! $row)) {
                    continue;
                }
                $price = $prices->get($item->id);
                $rate = $item->rate3 ?? $item->rate2 ?? $item->rate1;
                $rateId = $item->rate3 !== null ? $item->c3_id : ($item->rate2 !== null ? $item->c2_id : $item->c1_id);
                $net = self::netSalePrice($price?->sale_unit_price, $price?->type, $price?->tax_exempt_unit_price);
                $unit = $net !== null && $rate !== null && (float) $rate >= 0 && (float) $rate <= 100
                    ? round($net * (float) $rate / 100, 6) : null;
                $row ??= new WmsSundryValuationItem;
                $row->fill([
                    'sundry_inventory_count_id' => $count->id, 'amount_id' => $amountId,
                    'item_id' => $item->id, 'item_code' => $item->code, 'item_name' => trim((string) $item->name),
                    'price_id' => $price?->id, 'price_date' => $date, 'price_start_date' => $price?->start_date,
                    'price_tax_type' => $price?->type, 'sale_price' => $price?->sale_unit_price,
                    'net_sale_price' => $net, 'cost_rate' => $rate, 'rate_category_id' => $rateId,
                    'valuation_unit_price' => $unit,
                ]);
                if ($row->exists && $row->isDirty()) {
                    $row->revision++;
                }
                $row->save();
                $saved++;
            }

            return $saved;
        });
    }

    public static function netSalePrice(mixed $price, ?string $taxType, mixed $exempt = 0): ?float
    {
        if ($price === null || ! is_numeric($price)) {
            return null;
        }
        $divisor = match ($taxType) {
            'EXEMPT', 'POST_TAX_PERCENT_10', 'POST_TAX_PERCENT_8' => 1,
            'PRE_TAX_PERCENT_10' => 1.1,
            'PRE_TAX_PERCENT_8' => 1.08,
            default => null,
        };

        return $divisor === null ? null : round(((float) $price - (float) $exempt) / $divisor + (float) $exempt, 6);
    }

    /** @param array<int|string, array{quantity: mixed, revision: int}> $changes */
    public function save(WmsSundryInventoryCount $count, array $changes, string $actor): int
    {
        return DB::connection('sakemaru')->transaction(function () use ($count, $changes, $actor): int {
            $count = $this->lockEditable($count);
            $rows = WmsSundryValuationItem::where('sundry_inventory_count_id', $count->id)
                ->whereIn('id', array_keys($changes))->get();
            if ($rows->count() !== count($changes)) {
                $this->fail('対象の入力明細が変わっています。再読込してください。');
            }
            foreach ($rows as $row) {
                $change = $changes[$row->id];
                if (! is_array($change) || ! array_key_exists('quantity', $change)
                    || ! isset($change['revision']) || (int) $change['revision'] !== $row->revision) {
                    $this->fail('他の画面で入力が更新されました。再読込してから入力してください。');
                }
                if ($change['quantity'] !== null && ! is_scalar($change['quantity'])) {
                    $this->fail('数量は数値で入力してください。');
                }
                $text = str_replace(',', '', trim(mb_convert_kana((string) $change['quantity'], 'as')));
                if ($text !== '' && (! preg_match('/^\d{1,9}(?:\.\d{1,3})?$/D', $text))) {
                    $this->fail("商品{$row->item_code}の数量は0以上、小数3桁以内で入力してください。");
                }
                $quantity = $text === '' ? null : $text;
                if ($quantity !== null && (float) $quantity > 0 && ($row->valuation_unit_price === null || (float) $row->valuation_unit_price <= 0)) {
                    $this->fail("商品{$row->item_code}の販売単価・分類原価率を確認してください。0円での評価はできません。");
                }
                $row->quantity = $quantity;
                $row->amount = $quantity === null ? null : round((float) $quantity * (float) $row->valuation_unit_price, 2);
                if ($row->amount !== null && (float) $row->amount > 9999999999999.99) {
                    $this->fail('評価額が上限を超えています。数量・単価を確認してください。');
                }
                $row->revision++;
                $row->counted_by_name = $quantity === null ? null : $actor;
                $row->counted_at = $quantity === null ? null : now();
                $row->save();
            }
            foreach ($rows->pluck('amount_id')->unique() as $amountId) {
                $this->refreshCategory((int) $amountId, $actor);
            }

            return $rows->count();
        });
    }

    public function assertComplete(WmsSundryInventoryCount $count): void
    {
        if (self::available() && WmsSundryValuationItem::where('sundry_inventory_count_id', $count->id)->whereNull('quantity')->exists()) {
            $this->fail('在庫管理なしの商品数量に未入力が残っています。未入力は0とは扱いません。');
        }
    }

    private function refreshCategory(int $amountId, string $actor): void
    {
        $rows = WmsSundryValuationItem::where('amount_id', $amountId)->get();
        $amount = WmsSundryInventoryCountAmount::findOrFail($amountId);
        $complete = $rows->every(fn ($row) => $row->quantity !== null && $row->amount !== null);
        if ($complete && (float) $rows->sum('amount') > 9999999999999.99) {
            $this->fail('分類別の評価合計が上限を超えています。数量・単価を確認してください。');
        }
        // Feed the existing category total once, never add it again in summaries.
        $amount->counted_amount = $complete ? round($rows->sum('amount'), 2) : null;
        $amount->counted_by_name = $complete ? $actor : null;
        $amount->counted_at = $complete ? now() : null;
        $amount->recalculate()->save();
    }

    private function lockEditable(WmsSundryInventoryCount $count): WmsSundryInventoryCount
    {
        $locked = WmsSundryInventoryCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isEditable() || $locked->isWeighed()) {
            $this->fail('入力中の雑貨棚卸しだけ変更できます。');
        }

        return $locked;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['valuation' => $message]);
    }
}
