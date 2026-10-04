<?php

namespace App\Services\SundryInventoryCount;

use App\Models\Sakemaru\Warehouse;
use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * 棚卸し（雑貨）の作成・理論更新・実棚入力・確定。
 *
 * 既存の棚卸し（InventoryCountService / wms_inventory_counts）のデータと処理には触れない。
 * 在庫・伝票への書き込みも行わない（調整伝票は従来どおり基幹で起票する）。
 */
class SundryInventoryCountService
{
    private SundryInventoryTheoryCalculator $calculator;

    public function __construct(?SundryInventoryTheoryCalculator $calculator = null)
    {
        $this->calculator = $calculator ?? new SundryInventoryTheoryCalculator;
    }

    /**
     * 既定の対象中分類（設定の中分類コード → item_categories.id）。
     *
     * @return array<int, int>
     */
    public function defaultCategoryIds(?int $clientId = null): array
    {
        return $this->categoryIdsByCodes(SundryInventorySettings::defaultCategoryCodes(), 2, $clientId);
    }

    /**
     * 金額で棚卸しする既定の大分類（設定の大分類コード → item_categories.id）。
     *
     * @return array<int, int>
     */
    public function defaultAmountCategoryIds(?int $clientId = null): array
    {
        return $this->categoryIdsByCodes(SundryInventorySettings::defaultAmountCategoryCodes(), 1, $clientId);
    }

    /**
     * 作成モーダル用の中分類選択肢。
     *
     * @return array<int, string> item_categories.id => "[code]name"
     */
    public function categoryOptions(): array
    {
        return $this->categoryOptionsForDepth(2);
    }

    /**
     * 作成モーダル用の大分類選択肢（金額で棚卸しする在庫管理なし商品）。
     *
     * @return array<int, string> item_categories.id => "[code]name"
     */
    public function amountCategoryOptions(): array
    {
        return $this->categoryOptionsForDepth(1);
    }

    /**
     * @param  array{warehouse_id: int|string, count_date: string, category_ids?: array<int, int|string>, amount_category_ids?: array<int, int|string>, memo?: ?string}  $data
     */
    public function create(array $data): WmsSundryInventoryCount
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $clientId = (int) $warehouse->client_id;
        $countDate = CarbonImmutable::parse($data['count_date'])->toDateString();
        $categories = $this->categoriesById($data['category_ids'] ?? [], $clientId, 2);
        $amountCategories = $this->categoriesById($data['amount_category_ids'] ?? [], $clientId, 1);

        if ($categories->isEmpty() && $amountCategories->isEmpty()) {
            throw ValidationException::withMessages(['category_ids' => '対象の中分類、または金額で棚卸しする大分類を1つ以上選択してください。']);
        }

        // 未来日の棚卸しは、作成時点では当日までの受払で理論値を作る（棚卸し日以降に理論在庫更新する）。
        $theoryEndDate = min($countDate, now()->toDateString());
        $balances = $this->calculator->managedBalances($clientId, (int) $warehouse->id, $theoryEndDate);

        return DB::connection('sakemaru')->transaction(function () use ($warehouse, $clientId, $countDate, $categories, $amountCategories, $theoryEndDate, $balances, $data) {
            $count = WmsSundryInventoryCount::create([
                'count_no' => WmsSundryInventoryCount::generateCountNo($countDate),
                'client_id' => $clientId,
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => (string) ($warehouse->code ?? ''),
                'warehouse_name' => (string) ($warehouse->name ?? ''),
                'count_date' => $countDate,
                'status' => WmsSundryInventoryCount::STATUS_COUNTING,
                'category_ids' => $categories->keys()->map(fn ($id): int => (int) $id)->values()->all(),
                'amount_category_ids' => $amountCategories->keys()->map(fn ($id): int => (int) $id)->values()->all(),
                'theory_end_date' => $theoryEndDate,
                'theory_updated_at' => now(),
                'memo' => $data['memo'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncManagedItems($count, $categories, $balances);
            $this->syncAmountRows($count);

            return $count;
        });
    }

    /**
     * 受払終了日を指定して理論値（数量・原価・前残・金額受払）を再計算する。実棚の入力値は変更しない。
     *
     * 前残金額は、手入力した行を除いて取り直す（前回棚卸の実棚 → 旧システム残高の順）。
     * resetManualOpenings = true のときは手入力した前残も取り直した値で上書きする。
     *
     * @return array{end_date: string, updated_items: int, inserted_items: int, updated_amounts: int, inserted_amounts: int}
     */
    public function refreshTheory(WmsSundryInventoryCount $count, string $endDate, bool $resetManualOpenings = false): array
    {
        $endDate = CarbonImmutable::parse($endDate)->toDateString();

        if ($endDate > now()->toDateString()) {
            throw ValidationException::withMessages(['end_date' => '未来日の受払では理論在庫を更新できません。']);
        }

        $this->assertEditable($count);
        $balances = $this->calculator->managedBalances((int) $count->client_id, (int) $count->warehouse_id, $endDate);

        return $this->withEditableCount($count, function (WmsSundryInventoryCount $count) use ($endDate, $balances, $resetManualOpenings): array {
            $count->update([
                'theory_end_date' => $endDate,
                'theory_updated_at' => now(),
            ]);

            $clientId = (int) $count->client_id;
            $itemStats = $this->syncManagedItems($count, $this->categoriesById($count->targetCategoryIds(), $clientId, 2), $balances);
            $amountStats = $this->syncAmountRows($count, [], $resetManualOpenings);

            return [
                'end_date' => $endDate,
                'updated_items' => $itemStats['updated'],
                'inserted_items' => $itemStats['inserted'],
                'updated_amounts' => $amountStats['updated'],
                'inserted_amounts' => $amountStats['inserted'],
            ];
        });
    }

    /**
     * 画面からの入力を反映する。
     *
     * @param  array<int|string, mixed>  $itemChanges  数量明細ID => 実棚数（null で未入力に戻す）
     * @param  array<int|string, array<string, mixed>>  $amountChanges  金額明細ID => [counted_amount?, opening_amount?, opening_date?]
     * @return array{items: int, amounts: int}
     */
    public function saveChanges(WmsSundryInventoryCount $count, array $itemChanges, array $amountChanges, string $actorName): array
    {
        return $this->withEditableCount($count, function (WmsSundryInventoryCount $count) use ($itemChanges, $amountChanges, $actorName): array {
            $now = now();
            $savedItems = 0;
            $savedAmounts = 0;

            $itemIds = array_map('intval', array_keys($itemChanges));
            if ($itemIds !== []) {
                WmsSundryInventoryCountItem::query()
                    ->where('sundry_inventory_count_id', $count->id)
                    ->whereIn('id', $itemIds)
                    ->get()
                    ->each(function (WmsSundryInventoryCountItem $item) use ($itemChanges, $actorName, $now, &$savedItems): void {
                        $quantity = $this->parseNumber($itemChanges[$item->id] ?? null, 3, "商品{$item->item_code}の実棚数");

                        $item->counted_quantity = $quantity;
                        $item->counted_by_name = $quantity === null ? null : $actorName;
                        $item->counted_at = $quantity === null ? null : $now;
                        $item->recalculate()->save();
                        $savedItems++;
                    });
            }

            $amountIds = array_map('intval', array_keys($amountChanges));
            if ($amountIds !== []) {
                $rows = WmsSundryInventoryCountAmount::query()
                    ->where('sundry_inventory_count_id', $count->id)
                    ->whereIn('id', $amountIds)
                    ->get();
                $openingDateChanged = collect();

                foreach ($rows as $row) {
                    $change = (array) ($amountChanges[$row->id] ?? []);
                    $label = "中分類{$row->category2_code}";
                    $openingEdited = false;

                    if (array_key_exists('counted_amount', $change)) {
                        $amount = $this->parseNumber($change['counted_amount'], 2, "{$label}の実棚金額");
                        $row->counted_amount = $amount;
                        $row->counted_by_name = $amount === null ? null : $actorName;
                        $row->counted_at = $amount === null ? null : $now;
                    }

                    if (array_key_exists('opening_amount', $change)) {
                        $openingAmount = $this->parseNumber($change['opening_amount'], 2, "{$label}の前残金額") ?? 0.0;
                        if (round($openingAmount, 2) !== round((float) $row->opening_amount, 2)) {
                            $row->opening_amount = $openingAmount;
                            $openingEdited = true;
                        }
                    }

                    if (array_key_exists('opening_date', $change)) {
                        $openingDate = $this->parseDate($change['opening_date'], "{$label}の基準日");
                        if ($openingDate !== $row->opening_date?->toDateString()) {
                            $row->opening_date = $openingDate;
                            $openingEdited = true;
                            $openingDateChanged->push($row);
                        }
                    }

                    // 前残を手で直した行は、理論在庫更新でも自動の値に戻さない。
                    if ($openingEdited) {
                        $row->opening_source = WmsSundryInventoryCountAmount::OPENING_SOURCE_MANUAL;
                    }

                    $row->recalculate()->save();
                    $savedAmounts++;
                }

                // 基準日が変わった行は受払を取り直す。
                if ($openingDateChanged->isNotEmpty()) {
                    $this->applyUnmanagedFlows($count, $openingDateChanged);
                }
            }

            return ['items' => $savedItems, 'amounts' => $savedAmounts];
        });
    }

    /**
     * 未入力の数量明細を実棚0で埋める（金額明細は対象外）。
     */
    public function fillUncountedWithZero(WmsSundryInventoryCount $count, string $actorName): int
    {
        return $this->withEditableCount($count, function (WmsSundryInventoryCount $count) use ($actorName): int {
            $filled = 0;
            $now = now();

            WmsSundryInventoryCountItem::query()
                ->where('sundry_inventory_count_id', $count->id)
                ->whereNull('counted_quantity')
                ->orderBy('id')
                ->get()
                ->each(function (WmsSundryInventoryCountItem $item) use ($actorName, $now, &$filled): void {
                    $item->counted_quantity = 0;
                    $item->counted_by_name = $actorName;
                    $item->counted_at = $now;
                    $item->recalculate()->save();
                    $filled++;
                });

            return $filled;
        });
    }

    /**
     * 対象中分類以外の商品も含め、商品CDで明細を追加する。
     * 在庫管理ありは数量明細、在庫管理なしはその中分類の金額明細を追加する。
     *
     * @return array{type: string, inserted: bool, item_code: string, category_code: string}
     */
    public function addItemByCode(WmsSundryInventoryCount $count, string $itemCode): array
    {
        $itemCode = trim(mb_convert_kana($itemCode, 'as'));
        if ($itemCode === '') {
            throw ValidationException::withMessages(['item_code' => '商品CDを入力してください。']);
        }

        $this->assertEditable($count);
        $clientId = (int) $count->client_id;

        $item = DB::connection('sakemaru')
            ->table('items as i')
            ->leftJoin('item_categories as c1', 'c1.id', '=', 'i.item_category1_id')
            ->leftJoin('item_categories as c2', 'c2.id', '=', 'i.item_category2_id')
            ->where('i.client_id', $clientId)
            ->where('i.code', $itemCode)
            ->first([
                'i.id', 'i.code', 'i.name', 'i.type', 'i.is_managed_stock', 'i.item_category1_id', 'i.item_category2_id',
                'c1.code as category1_code', 'c2.code as category2_code', 'c2.name as category2_name',
            ]);

        if (! $item) {
            throw ValidationException::withMessages(['item_code' => "商品CD {$itemCode} が見つかりません。"]);
        }

        $isUnmanaged = $item->is_managed_stock !== null && ! (bool) $item->is_managed_stock;

        if ($isUnmanaged) {
            if (! $item->item_category2_id || $item->category2_code === null) {
                throw ValidationException::withMessages(['item_code' => "商品CD {$itemCode} は中分類が未設定のため、金額明細に追加できません。"]);
            }

            return $this->withEditableCount($count, function (WmsSundryInventoryCount $count) use ($item): array {
                $exists = WmsSundryInventoryCountAmount::query()
                    ->where('sundry_inventory_count_id', $count->id)
                    ->where('category2_id', (int) $item->item_category2_id)
                    ->exists();

                if (! $exists) {
                    $this->syncAmountRows($count, [(int) $item->item_category2_id]);
                }

                return [
                    'type' => 'amount',
                    'inserted' => ! $exists,
                    'item_code' => (string) $item->code,
                    'category_code' => (string) $item->category2_code,
                ];
            });
        }

        if ((string) ($item->type ?? '') === 'CONTAINER') {
            throw ValidationException::withMessages(['item_code' => "商品CD {$itemCode} は容器のため、棚卸し（雑貨）の対象外です。"]);
        }

        $endDate = $this->theoryEndDate($count);
        $balances = $this->calculator->managedBalances($clientId, (int) $count->warehouse_id, $endDate);
        $costPrices = $this->calculator->costPrices($clientId, [(int) $item->id], $count->count_date->toDateString());

        return $this->withEditableCount($count, function (WmsSundryInventoryCount $count) use ($item, $balances, $costPrices): array {
            $exists = WmsSundryInventoryCountItem::query()
                ->where('sundry_inventory_count_id', $count->id)
                ->where('item_id', (int) $item->id)
                ->exists();

            if (! $exists) {
                $row = new WmsSundryInventoryCountItem([
                    'sundry_inventory_count_id' => $count->id,
                    'item_id' => (int) $item->id,
                    'item_code' => (string) $item->code,
                    'item_name' => trim((string) $item->name),
                    'category2_id' => $item->item_category2_id ? (int) $item->item_category2_id : null,
                    'category2_code' => (string) ($item->category2_code ?? ''),
                    'category2_name' => $this->cleanCategoryName($item->category2_name),
                    'is_additional' => ! in_array((int) $item->item_category2_id, $count->targetCategoryIds(), true),
                    'cost_price' => (float) ($costPrices[(int) $item->id] ?? 0),
                    'system_quantity' => round((float) ($balances[(int) $item->id] ?? 0), 3),
                ]);
                $row->recalculate()->save();
            }

            return [
                'type' => 'item',
                'inserted' => ! $exists,
                'item_code' => (string) $item->code,
                'category_code' => (string) ($item->category2_code ?? ''),
            ];
        });
    }

    public function confirm(WmsSundryInventoryCount $count, ?int $userId): void
    {
        $this->withEditableCount($count, function (WmsSundryInventoryCount $count) use ($userId): void {
            $count->update([
                'status' => WmsSundryInventoryCount::STATUS_CONFIRMED,
                'confirmed_at' => now(),
                'confirmed_by' => $userId,
            ]);
        });
    }

    /**
     * 確定を解除して入力中に戻す。在庫・伝票には何も書いていないため、戻しても影響はない。
     */
    public function reopen(WmsSundryInventoryCount $count): void
    {
        DB::connection('sakemaru')->transaction(function () use ($count): void {
            $locked = WmsSundryInventoryCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== WmsSundryInventoryCount::STATUS_CONFIRMED) {
                throw ValidationException::withMessages(['sundry_inventory_count' => '確定済みの棚卸しだけ確定解除できます。']);
            }

            $locked->update([
                'status' => WmsSundryInventoryCount::STATUS_COUNTING,
                'confirmed_at' => null,
                'confirmed_by' => null,
            ]);
        });
    }

    public function cancel(WmsSundryInventoryCount $count): void
    {
        $this->withEditableCount($count, function (WmsSundryInventoryCount $count): void {
            $count->update(['status' => WmsSundryInventoryCount::STATUS_CANCELLED]);
        });
    }

    /**
     * 金額集計（在庫管理あり・なしとも中分類別）。
     *
     * 各行: code / name / detail_count / uncounted_count / system / counted / difference
     *
     * @return array{managed: array<int, array<string, mixed>>, unmanaged: array<int, array<string, mixed>>, totals: array{managed: array<string, mixed>, unmanaged: array<string, mixed>, total: array<string, mixed>}}
     */
    public function summary(WmsSundryInventoryCount $count): array
    {
        $managed = DB::connection('sakemaru')
            ->table('wms_sundry_inventory_count_items')
            ->where('sundry_inventory_count_id', $count->id)
            ->groupBy('category2_code')
            ->orderBy('category2_code')
            ->selectRaw('category2_code, MAX(category2_name) as category2_name, COUNT(*) as detail_count')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NULL THEN 1 ELSE 0 END) as uncounted_count')
            ->selectRaw('SUM(system_amount) as system_amount, SUM(COALESCE(counted_amount, 0)) as counted_amount, SUM(COALESCE(difference_amount, 0)) as difference_amount')
            ->get()
            ->map(fn ($row): array => [
                'code' => (string) $row->category2_code,
                'name' => (string) $row->category2_name,
                'detail_count' => (int) $row->detail_count,
                'uncounted_count' => (int) $row->uncounted_count,
                'system' => round((float) $row->system_amount, 2),
                'counted' => round((float) $row->counted_amount, 2),
                'difference' => round((float) $row->difference_amount, 2),
            ])
            ->all();

        $unmanaged = WmsSundryInventoryCountAmount::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->orderBy('category2_code')
            ->orderBy('id')
            ->get()
            ->map(fn (WmsSundryInventoryCountAmount $row): array => [
                'code' => (string) $row->category2_code,
                'name' => (string) $row->category2_name,
                'detail_count' => 1,
                'uncounted_count' => $row->counted_amount === null ? 1 : 0,
                'system' => round((float) $row->system_amount, 2),
                'counted' => round((float) ($row->counted_amount ?? 0), 2),
                'difference' => round((float) ($row->difference_amount ?? 0), 2),
            ])
            ->all();

        $sum = fn (array $lines, string $name): array => [
            'code' => '',
            'name' => $name,
            'detail_count' => array_sum(array_column($lines, 'detail_count')),
            'uncounted_count' => array_sum(array_column($lines, 'uncounted_count')),
            'system' => round(array_sum(array_column($lines, 'system')), 2),
            'counted' => round(array_sum(array_column($lines, 'counted')), 2),
            'difference' => round(array_sum(array_column($lines, 'difference')), 2),
        ];

        return [
            'managed' => $managed,
            'unmanaged' => $unmanaged,
            'totals' => [
                'managed' => $sum($managed, '在庫管理あり 計'),
                'unmanaged' => $sum($unmanaged, '在庫管理なし 計'),
                'total' => $sum([...$managed, ...$unmanaged], '合計'),
            ],
        ];
    }

    /**
     * 対象中分類の在庫管理あり商品を数量明細に同期する（既存行は理論数・原価を更新、未登録は追加）。
     *
     * @param  Collection<int, object>  $categories  id => category
     * @param  array<int, float>  $balances
     * @return array{updated: int, inserted: int}
     */
    private function syncManagedItems(WmsSundryInventoryCount $count, Collection $categories, array $balances): array
    {
        $clientId = (int) $count->client_id;
        $warehouseId = (int) $count->warehouse_id;
        $costDate = $count->count_date->toDateString();

        $existing = WmsSundryInventoryCountItem::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->get()
            ->keyBy(fn (WmsSundryInventoryCountItem $item): int => (int) $item->item_id);

        $candidates = collect();
        if ($categories->isNotEmpty()) {
            $query = DB::connection('sakemaru')
                ->table('items as i')
                ->where('i.client_id', $clientId)
                ->whereIn('i.item_category2_id', $categories->keys()->all())
                ->where(function ($query): void {
                    $query->whereNull('i.is_managed_stock')->orWhere('i.is_managed_stock', true);
                })
                ->whereRaw("COALESCE(i.type, '') <> 'CONTAINER'");

            if (Schema::connection('sakemaru')->hasTable('item_sets')) {
                $query->whereNotExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('item_sets as owned_sets')
                        ->whereColumn('owned_sets.id', 'i.item_set_id')
                        ->where('owned_sets.set_type', 'OWNED')
                        ->where('owned_sets.is_active', true);
                });
            }

            $candidates = $query
                ->orderBy('i.code')
                ->get(['i.id', 'i.code', 'i.name', 'i.item_category2_id']);
        }

        // 既存棚卸しと同じく「倉庫に在庫行がある」または「受払残が0でない」商品を対象にする。
        $stockItemIds = [];
        foreach ($candidates->pluck('id')->chunk(1000) as $chunk) {
            DB::connection('sakemaru')
                ->table('real_stocks')
                ->where('client_id', $clientId)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('item_id', $chunk->all())
                ->distinct()
                ->pluck('item_id')
                ->each(function ($itemId) use (&$stockItemIds): void {
                    $stockItemIds[(int) $itemId] = true;
                });
        }

        $newItems = $candidates->filter(function ($item) use ($existing, $stockItemIds, $balances): bool {
            $itemId = (int) $item->id;

            return ! $existing->has($itemId)
                && (isset($stockItemIds[$itemId]) || abs((float) ($balances[$itemId] ?? 0)) > 0.0005);
        });

        $costPrices = $this->calculator->costPrices(
            $clientId,
            $existing->keys()->merge($newItems->pluck('id'))->map(fn ($id): int => (int) $id)->all(),
            $costDate,
        );

        $updated = 0;
        foreach ($existing as $itemId => $row) {
            $row->system_quantity = round((float) ($balances[$itemId] ?? 0), 3);
            $row->cost_price = (float) ($costPrices[$itemId] ?? $row->cost_price ?? 0);
            $row->recalculate();

            if ($row->isDirty()) {
                $row->save();
                $updated++;
            }
        }

        $now = now();
        $records = [];
        foreach ($newItems as $item) {
            $itemId = (int) $item->id;
            $category = $categories->get((int) $item->item_category2_id);
            $cost = (float) ($costPrices[$itemId] ?? 0);
            $systemQuantity = round((float) ($balances[$itemId] ?? 0), 3);

            $records[] = [
                'sundry_inventory_count_id' => $count->id,
                'item_id' => $itemId,
                'item_code' => (string) $item->code,
                'item_name' => trim((string) $item->name),
                'category2_id' => (int) $item->item_category2_id,
                'category2_code' => (string) ($category->code ?? ''),
                'category2_name' => $this->cleanCategoryName($category->name ?? ''),
                'is_additional' => false,
                'cost_price' => $cost,
                'system_quantity' => $systemQuantity,
                'system_amount' => round($systemQuantity * $cost, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($records, 500) as $chunk) {
            WmsSundryInventoryCountItem::insert($chunk);
        }

        return ['updated' => $updated, 'inserted' => count($records)];
    }

    /**
     * 在庫管理なし商品を持つ中分類の金額明細を同期する。
     *
     * 作成・理論在庫更新のとき（additionalCategory2Ids が空）:
     *   対象中分類と、金額で棚卸しする大分類に含まれる中分類のうち、未登録のものを追加し、
     *   全行の前残金額（手入力の行を除く）と受払を取り直す。
     * 商品追加のとき:
     *   指定された中分類だけを追加・計算する（既存の行は触らない）。
     *
     * @param  array<int, int>  $additionalCategory2Ids  商品追加で増やす中分類
     * @return array{updated: int, inserted: int}
     */
    private function syncAmountRows(
        WmsSundryInventoryCount $count,
        array $additionalCategory2Ids = [],
        bool $resetManualOpenings = false,
    ): array {
        $clientId = (int) $count->client_id;
        $isAddition = $additionalCategory2Ids !== [];
        $selectedCategory1Ids = array_flip($count->amountCategoryIds());
        $selectedCategory2Ids = array_flip($count->targetCategoryIds());

        // only() を中分類IDのキーで使うため、Eloquentコレクションではなく通常のコレクションにする。
        $existing = WmsSundryInventoryCountAmount::query()
            ->where('sundry_inventory_count_id', $count->id)
            ->get()
            ->toBase()
            ->keyBy(fn (WmsSundryInventoryCountAmount $row): int => (int) $row->category2_id);

        $categories = $isAddition
            ? $this->calculator->unmanagedCategories($clientId, [], $additionalCategory2Ids)
            : $this->calculator->unmanagedCategories($clientId, $count->amountCategoryIds(), $count->targetCategoryIds());

        $insertedIds = [];
        foreach ($categories as $category) {
            $category2Id = (int) $category->category2_id;
            if ($existing->has($category2Id)) {
                continue;
            }

            $existing->put($category2Id, new WmsSundryInventoryCountAmount([
                'sundry_inventory_count_id' => $count->id,
                'category1_id' => $category->category1_id !== null ? (int) $category->category1_id : null,
                'category1_code' => (string) ($category->category1_code ?? ''),
                'category1_name' => $this->cleanCategoryName($category->category1_name ?? ''),
                'category2_id' => $category2Id,
                'category2_code' => (string) $category->category2_code,
                'category2_name' => $this->cleanCategoryName($category->category2_name),
                'is_additional' => ! isset($selectedCategory1Ids[(int) $category->category1_id]) && ! isset($selectedCategory2Ids[$category2Id]),
                'opening_source' => WmsSundryInventoryCountAmount::OPENING_SOURCE_DEFAULT,
            ]));
            $insertedIds[] = $category2Id;
        }

        // 商品追加のときは追加した中分類だけ、作成・理論更新のときは全行を計算し直す。
        $targets = $isAddition
            ? $existing->only($insertedIds)->values()
            : $existing->values();

        foreach ($targets as $row) {
            if ($row->exists
                && $row->opening_source === WmsSundryInventoryCountAmount::OPENING_SOURCE_MANUAL
                && ! $resetManualOpenings
            ) {
                continue;
            }

            $this->applyOpening($count, $row);
        }

        $applied = $this->applyUnmanagedFlows($count, $targets);
        $this->refreshReportReferences($count);

        return ['updated' => max(0, $applied - count($insertedIds)), 'inserted' => count($insertedIds)];
    }

    /**
     * 前残金額を設定する（保存はしない）。優先順:
     *  1. 同じ倉庫・中分類の、前回確定した棚卸し（雑貨）の実棚金額（基準日 = その棚卸し日）
     *  2. 旧システム（Ｔ３在庫・在庫管理区分=0）の最終残高（基準日 = 旧システム最終日 2026-05-05。受払は 5/6 から）
     *  3. どちらも無ければ設定の基準日・0円（画面で手入力する）
     */
    private function applyOpening(WmsSundryInventoryCount $count, WmsSundryInventoryCountAmount $row): void
    {
        $endDate = $this->theoryEndDate($count);

        $previous = DB::connection('sakemaru')
            ->table('wms_sundry_inventory_count_amounts as a')
            ->join('wms_sundry_inventory_counts as c', 'c.id', '=', 'a.sundry_inventory_count_id')
            ->where('c.client_id', $count->client_id)
            ->where('c.warehouse_id', $count->warehouse_id)
            ->where('c.status', WmsSundryInventoryCount::STATUS_CONFIRMED)
            ->where('c.count_date', '<', $count->count_date->toDateString())
            ->where('c.count_date', '<=', $endDate)
            ->where('c.id', '!=', $count->id)
            ->where('a.category2_id', $row->category2_id)
            ->whereNotNull('a.counted_amount')
            ->orderByDesc('c.count_date')
            ->orderByDesc('c.id')
            ->first(['c.count_date', 'a.counted_amount']);

        if ($previous) {
            $row->opening_date = CarbonImmutable::parse($previous->count_date)->toDateString();
            $row->opening_amount = (float) $previous->counted_amount;
            $row->opening_source = WmsSundryInventoryCountAmount::OPENING_SOURCE_PREVIOUS_COUNT;

            return;
        }

        $legacy = $this->calculator->unmanagedLegacyOpening((string) $count->warehouse_code, (string) $row->category2_code, $endDate);
        if ($legacy !== null) {
            $row->opening_date = $legacy['date'];
            $row->opening_amount = $legacy['amount'];
            $row->opening_source = WmsSundryInventoryCountAmount::OPENING_SOURCE_LEGACY;

            return;
        }

        $row->opening_date = min(SundryInventorySettings::defaultOpeningDate(), $endDate);
        $row->opening_amount = 0;
        $row->opening_source = WmsSundryInventoryCountAmount::OPENING_SOURCE_DEFAULT;
    }

    /**
     * 金額明細に、基準日の翌日〜受払終了日の受払を反映して保存する。
     *
     * @param  Collection<int, WmsSundryInventoryCountAmount>  $rows
     */
    private function applyUnmanagedFlows(WmsSundryInventoryCount $count, Collection $rows): int
    {
        $endDate = $this->theoryEndDate($count);
        $applied = 0;

        $rows
            ->groupBy(fn (WmsSundryInventoryCountAmount $row): string => $row->opening_date?->toDateString() ?? '')
            ->each(function (Collection $group, string $openingDate) use ($count, $endDate, &$applied): void {
                // 基準日未設定の行は受払を積まない（理論金額 = 前残金額）。
                $flows = $openingDate === '' ? [] : $this->calculator->unmanagedFlows(
                    (int) $count->client_id,
                    (int) $count->warehouse_id,
                    $group->pluck('category2_id')->map(fn ($id): int => (int) $id)->all(),
                    CarbonImmutable::parse($openingDate)->addDay()->toDateString(),
                    $endDate,
                    2,
                );

                foreach ($group as $row) {
                    $flow = $flows[(int) $row->category2_id] ?? [];

                    $row->purchase_amount = (float) ($flow['purchase'] ?? 0);
                    $row->transfer_in_amount = (float) ($flow['transfer_in'] ?? 0);
                    $row->transfer_out_amount = (float) ($flow['transfer_out'] ?? 0);
                    $row->sales_cost_amount = (float) ($flow['sales_cost'] ?? 0);
                    $row->adjustment_amount = (float) ($flow['adjustment'] ?? 0);
                    $row->recalculate()->save();
                    $applied++;
                }
            });

        return $applied;
    }

    /**
     * 在庫金額報告書ベースの非管理品残高（大分類別）を計算してヘッダーに保存する。
     *
     * 中分類別の理論金額の合計が、公式帳簿（在庫金額報告書）とどれだけ合っているかを見るための突合用。
     *   報告書ベースの理論金額 = (報告書の月末金額 − 在庫管理あり評価額) + 月末の翌日〜受払終了日の受払
     */
    private function refreshReportReferences(WmsSundryInventoryCount $count): void
    {
        $clientId = (int) $count->client_id;
        $warehouseId = (int) $count->warehouse_id;
        $endDate = $this->theoryEndDate($count);
        $references = [];

        foreach ($this->categoriesById($count->amountCategoryIds(), $clientId, 1) as $categoryId => $category) {
            $reference = [
                'category1_id' => (int) $categoryId,
                'category1_code' => (string) $category->code,
                'category1_name' => $this->cleanCategoryName($category->name),
                'report_month' => null,
                'report_date' => null,
                'report_amount' => null,
                'managed_amount' => null,
                'opening_amount' => null,
                'flow_amount' => null,
                'system_amount' => null,
            ];

            $report = $this->calculator->unmanagedOpeningFromStockReport($clientId, $warehouseId, (int) $categoryId, $endDate);
            if ($report !== null) {
                $flow = $this->calculator->unmanagedFlows(
                    $clientId,
                    $warehouseId,
                    [(int) $categoryId],
                    CarbonImmutable::parse($report['date'])->addDay()->toDateString(),
                    $endDate,
                    1,
                )[(int) $categoryId];
                $flowAmount = round($flow['purchase'] + $flow['transfer_in'] - $flow['transfer_out'] - $flow['sales_cost'] + $flow['adjustment'], 2);

                $reference = array_merge($reference, [
                    'report_month' => $report['month'],
                    'report_date' => $report['date'],
                    'report_amount' => $report['report_amount'],
                    'managed_amount' => $report['managed_amount'],
                    'opening_amount' => $report['amount'],
                    'flow_amount' => $flowAmount,
                    'system_amount' => round($report['amount'] + $flowAmount, 2),
                ]);
            }

            $references[] = $reference;
        }

        $count->update(['amount_report_references' => $references]);
    }

    /**
     * 在庫金額報告書ベースの残高と、中分類別の理論金額の合計の突合（大分類別）。
     *
     * @return array<int, array<string, mixed>> 各行に detail_system_amount（中分類合計）と difference_amount（中分類合計 − 報告書ベース）を付ける
     */
    public function reportReferences(WmsSundryInventoryCount $count): array
    {
        $detailTotals = DB::connection('sakemaru')
            ->table('wms_sundry_inventory_count_amounts')
            ->where('sundry_inventory_count_id', $count->id)
            ->groupBy('category1_id')
            ->selectRaw('category1_id, SUM(system_amount) as system_amount')
            ->pluck('system_amount', 'category1_id');

        return array_map(function (array $reference) use ($detailTotals): array {
            $detail = round((float) ($detailTotals[$reference['category1_id']] ?? 0), 2);

            return $reference + [
                'detail_system_amount' => $detail,
                'difference_amount' => $reference['system_amount'] === null
                    ? null
                    : round($detail - (float) $reference['system_amount'], 2),
            ];
        }, array_values((array) ($count->amount_report_references ?? [])));
    }

    private function theoryEndDate(WmsSundryInventoryCount $count): string
    {
        return $count->theory_end_date?->toDateString()
            ?? min($count->count_date->toDateString(), now()->toDateString());
    }

    /**
     * @param  array<int, int>  $codes
     * @return array<int, int>
     */
    private function categoryIdsByCodes(array $codes, int $depth, ?int $clientId): array
    {
        if ($codes === []) {
            return [];
        }

        return DB::connection('sakemaru')
            ->table('item_categories')
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->where('depth', $depth)
            ->whereIn('code', $codes)
            ->orderBy('code')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<int, string> item_categories.id => "[code]name"
     */
    private function categoryOptionsForDepth(int $depth): array
    {
        return DB::connection('sakemaru')
            ->table('item_categories')
            ->where('depth', $depth)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($category): array => [
                (int) $category->id => '['.$category->code.']'.$this->cleanCategoryName($category->name),
            ])
            ->all();
    }

    /**
     * @param  array<int, int|string>  $categoryIds
     * @return Collection<int, object> id => category
     */
    private function categoriesById(array $categoryIds, int $clientId, int $depth): Collection
    {
        $categoryIds = array_values(array_unique(array_map('intval', array_filter($categoryIds))));
        if ($categoryIds === []) {
            return collect();
        }

        return DB::connection('sakemaru')
            ->table('item_categories')
            ->where('client_id', $clientId)
            ->where('depth', $depth)
            ->whereIn('id', $categoryIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->keyBy(fn ($category): int => (int) $category->id);
    }

    private function assertEditable(WmsSundryInventoryCount $count): void
    {
        if (! $count->isEditable()) {
            throw ValidationException::withMessages([
                'sundry_inventory_count' => "{$count->status_label}の棚卸しは変更できません。",
            ]);
        }
    }

    /**
     * ヘッダーをロックし、入力中であることを確認してから処理する。
     */
    private function withEditableCount(WmsSundryInventoryCount $count, \Closure $callback): mixed
    {
        return DB::connection('sakemaru')->transaction(function () use ($count, $callback) {
            $locked = WmsSundryInventoryCount::query()
                ->whereKey($count->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked);

            return $callback($locked);
        });
    }

    private function parseNumber(mixed $value, int $scale, string $label): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([',', '¥', '￥', ' '], '', mb_convert_kana(trim((string) $value), 'as'));
        if ($value === '') {
            return null;
        }

        if (preg_match('/\A-?\d+(\.\d+)?\z/', $value) !== 1) {
            throw ValidationException::withMessages(['changes' => "{$label}は数値で入力してください。"]);
        }

        $number = round((float) $value, $scale);
        if (abs($number) >= 1_000_000_000_000) {
            throw ValidationException::withMessages(['changes' => "{$label}が大きすぎます。"]);
        }

        return $number;
    }

    private function parseDate(mixed $value, string $label): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['changes' => "{$label}は日付で入力してください。"]);
        }
    }

    private function cleanCategoryName(mixed $name): string
    {
        return trim(str_replace(['【', '】', '（', '）', '(', ')', '　'], '', (string) ($name ?? '')));
    }
}
