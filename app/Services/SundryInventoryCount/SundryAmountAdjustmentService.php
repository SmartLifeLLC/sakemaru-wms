<?php

namespace App\Services\SundryInventoryCount;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryValuationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Immutable amount-only journal. This never calls the ordinary stock adjustment path. */
class SundryAmountAdjustmentService
{
    public const TABLE = 'wms_sundry_amount_adjustments';

    public static function isApplied(int $countId): bool
    {
        return Schema::connection('sakemaru')->hasTable(self::TABLE)
            && DB::connection('sakemaru')->table(self::TABLE)->where('sundry_inventory_count_id', $countId)->exists();
    }

    public function apply(WmsSundryInventoryCount $count, string $date, string $bookReference, ?int $userId): int
    {
        if (Schema::connection('sakemaru')->hasTable(SundryFinalizationService::TABLE)) {
            $this->fail('棚卸し最終確定で実棚変更伝票を記録し、在庫へ適用してください。旧金額適用は利用できません。');
        }
        if (! config('wms_sundry_inventory.amount_posting_enabled', false)) {
            $this->fail('金額適用はまだ有効になっていません。基幹側の報告書連携と帳簿確認後に有効化してください。');
        }
        if (mb_strlen(trim($bookReference)) < 5) {
            $this->fail('照合した帳簿の名称・基準日を入力してください。');
        }

        return DB::connection('sakemaru')->transaction(function () use ($count, $date, $bookReference, $userId): int {
            $db = DB::connection('sakemaru');
            // Same short client-level lock as the report publisher/run scheduler.
            $db->table('clients')->where('id', $count->client_id)->lockForUpdate()->firstOrFail();
            $count = WmsSundryInventoryCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            if (self::isApplied((int) $count->id)) {
                return 0;
            }
            if ($count->isWeighed() || $count->status !== WmsSundryInventoryCount::STATUS_CONFIRMED) {
                $this->fail('入力確定済みの雑貨棚卸しだけ金額適用できます。');
            }
            if (in_array((string) (int) $count->warehouse_code, ['23'], true)) {
                $this->fail('この倉庫は報告書の前月残高を固定する対象です。金額適用はできません。');
            }
            if ($date !== $count->count_date->toDateString() || $date !== $count->theory_end_date?->toDateString()) {
                $this->fail('棚卸し日・受払終了日・適用日は同じ日付にしてください。別月への遡及適用には別途受払の検証が必要です。');
            }
            if ($date > today()->toDateString()) {
                $this->fail('未来の日付には適用できません。');
            }
            $month = substr($date, 0, 7);
            if ($db->table('stats_monthly_stock_amount_reports')->where('client_id', $count->client_id)
                ->where('target_year_month', '>', $month)->exists()) {
                $this->fail('翌月以降の在庫金額報告書が保存されているため、この適用月は変更できません。');
            }
            if (Schema::connection('sakemaru')->hasTable('stock_report_generation_runs')
                && $db->table('stock_report_generation_runs')->where('client_id', $count->client_id)->whereIn('status', ['queued', 'running'])->exists()) {
                $this->fail('在庫金額報告書を生成中です。完了後に再実行してください。');
            }
            $valuation = new SundryQuantityValuationService;
            $valuation->assertComplete($count);
            $details = WmsSundryValuationItem::where('sundry_inventory_count_id', $count->id)->orderBy('id')->get()->groupBy('amount_id');
            if ($details->isEmpty()) {
                $this->fail('在庫管理なしの商品数量が登録されていません。');
            }
            $amounts = $count->amounts()->whereIn('id', $details->keys())->orderBy('id')->get();
            foreach ($amounts as $amount) {
                if ($db->table(self::TABLE)->where('client_id', $count->client_id)->where('warehouse_id', $count->warehouse_id)
                    ->where('category2_id', $amount->category2_id)->where('process_date', '>=', $date)->exists()) {
                    $this->fail('同じ店舗・中分類の同日以降に金額調整があります。重複・遡及適用はできません。');
                }
                if ($amount->counted_amount === null || $amount->opening_source === 'default'
                    || ! in_array((int) $amount->category1_code, [1004, 1005, 1002], true)) {
                    $this->fail('未設定の帳簿残高、未入力、または対象外の部門があります。金額明細を確認してください。');
                }
                $rows = $details[$amount->id];
                if (round((float) $amount->counted_amount, 2) !== round((float) $rows->sum('amount'), 2)) {
                    $this->fail('商品数量の評価合計と分類別の実棚金額が一致しません。');
                }
                // Reject changed master classification rather than silently mixing managed valuation.
                $valid = $db->table('items as i')->where('i.client_id', $count->client_id)
                    ->whereIn('i.id', $rows->pluck('item_id'))->where('i.is_managed_stock', false)
                    ->where('i.item_category1_id', $amount->category1_id)->where('i.item_category2_id', $amount->category2_id)
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('item_sets as s')->whereColumn('s.id', 'i.item_set_id')
                        ->where('s.set_type', 'OWNED')->where('s.is_active', true))->count();
                if ($valid !== $rows->count()) {
                    $this->fail('商品の在庫管理設定または分類が変わっています。適用せず停止しました。');
                }
                $db->table(self::TABLE)->insert([
                    'client_id' => $count->client_id, 'warehouse_id' => $count->warehouse_id,
                    'sundry_inventory_count_id' => $count->id, 'amount_id' => $amount->id,
                    'category1_id' => $amount->category1_id, 'category2_id' => $amount->category2_id,
                    'category1_code' => $amount->category1_code, 'category2_code' => $amount->category2_code,
                    'count_date' => $date, 'process_date' => $date, 'book_amount' => $amount->system_amount,
                    'counted_amount' => $amount->counted_amount,
                    'adjustment_amount' => round($amount->counted_amount - $amount->system_amount, 2),
                    'book_reference' => trim($bookReference),
                    'source_snapshot' => json_encode(['header' => $count->getAttributes(), 'category' => $amount->getAttributes(),
                        'items' => $rows->map(fn ($row) => $row->getAttributes())->all()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $amounts->count();
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['amount_adjustment' => $message]);
    }
}
