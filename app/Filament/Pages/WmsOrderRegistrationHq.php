<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Services\AutoOrder\HqOrderRegistrationReferenceService;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * 外部発注（本部） — 本部 91 番倉庫専用の発注登録画面。
 *
 * 既存の（新）外部発注（WmsOrderRegistration）を継承し、本部向けの違いだけをここに書く。
 * 親クラス・既存 Blade・既存サービスは変更しない。発注確定（登録・入荷予定・PDF 生成）は
 * 親の処理をそのまま使う。
 *
 * 本部画面での違い:
 * - 倉庫は本部倉庫（config: wms_hq_order_registration.warehouse_code）に固定
 * - 登録リスト: 発注先CDのプルダウン変更 / 納入先はコードのみ / 参考データ列を表示
 * - 発注候補検索: 最終入荷予定・最終仕入を表示、週実績から店舗（敦賀・小浜）の卸実績を除外
 * - 外部発注候補生成: 発注先（EOS・FAX 統合）と、発注先に紐づく仕入先で絞り込み
 */
class WmsOrderRegistrationHq extends WmsOrderRegistration
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $slug = 'wms-order-registration-hq';

    protected string $view = 'filament.pages.wms-order-registration-hq';

    /** 外部発注候補生成で選択中の仕入先ID */
    public array $selectedHqSupplierIds = [];

    /** 登録リストの参考データ（"倉庫ID:商品ID" => 参考データ） */
    public array $lineReferences = [];

    protected ?HqOrderRegistrationReferenceService $hqReferenceService = null;

    /** @var array{suppliers: array<int, array<string, mixed>>, links: array<string, array<int>>}|null */
    protected ?array $hqSupplierSelectionCache = null;

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_ORDER_REGISTRATION_HQ->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_ORDER_REGISTRATION_HQ->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_ORDER_REGISTRATION_HQ->sort();
    }

    public function getTitle(): string|HtmlString
    {
        return EMenu::WMS_ORDER_REGISTRATION_HQ->label();
    }

    public function mount(OrderRegistrationSearchService $searchService): void
    {
        parent::mount($searchService);

        $hqWarehouseId = $this->hqWarehouseId();
        $this->warehouseId = $hqWarehouseId > 0 ? $hqWarehouseId : null;

        if (! $this->warehouseId) {
            Notification::make()
                ->title('本部倉庫が見つかりません')
                ->body('倉庫CD '.$this->hqService()->hqWarehouseCode().' の倉庫マスタを確認してください。')
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * 本部画面では倉庫を切り替えない。画面外から書き換えられても本部倉庫へ戻す。
     */
    public function updatedWarehouseId(): void
    {
        $hqWarehouseId = $this->hqWarehouseId();
        $this->warehouseId = $hqWarehouseId > 0 ? $hqWarehouseId : null;

        parent::updatedWarehouseId();
    }

    public function hqWarehouseId(): int
    {
        return (int) ($this->hqService()->hqWarehouseId() ?? 0);
    }

    /**
     * 週実績の列ヘルプ。本部画面では店舗の卸実績を除いていることを明記する。
     *
     * @return array<string, string>
     */
    public function weeklySalesColumnHelps(): array
    {
        $helps = parent::weeklySalesColumnHelps();
        $exclusionLabel = $this->hqService()->salesExclusionLabel();

        if ($exclusionLabel === '') {
            return $helps;
        }

        return array_map(
            fn (string $help): string => $help."{$exclusionLabel}の卸実績（計上は本部、出荷は店舗）は含みません。",
            $helps,
        );
    }

    public function salesExclusionLabel(): string
    {
        return $this->hqService()->salesExclusionLabel();
    }

    public function lastPurchaseLookbackMonths(): int
    {
        return $this->hqService()->lastPurchaseLookbackMonths();
    }

    /**
     * 発注候補検索。親の検索結果に、本部用の参考データ（最終入荷予定・最終仕入・
     * 店舗卸を除いた週実績）を上書きして返す。
     */
    public function searchItemsForModal(
        int $warehouseId,
        ?string $itemCode = null,
        ?string $janCode = null,
        ?string $itemName = null,
        ?int $contractorId = null,
        ?int $category1Id = null,
        ?int $category2Id = null,
        ?int $category3Id = null,
        ?string $lastShippedFrom = null,
        ?string $lastShippedTo = null,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $hqWarehouseId = $this->hqWarehouseId();
        if ($hqWarehouseId < 1) {
            return ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1];
        }

        $result = parent::searchItemsForModal(
            $hqWarehouseId,
            $itemCode,
            $janCode,
            $itemName,
            $contractorId,
            $category1Id,
            $category2Id,
            $category3Id,
            $lastShippedFrom,
            $lastShippedTo,
            $page,
            $perPage,
        );

        $itemIds = collect($result['data'] ?? [])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $references = $this->hqService()->rowReferences($hqWarehouseId, $itemIds);

        $result['data'] = collect($result['data'] ?? [])
            ->map(fn (array $row): array => array_merge($row, $references[(int) ($row['id'] ?? 0)] ?? []))
            ->values()
            ->toArray();

        return $result;
    }

    public function openSalesHistoryModal(): void
    {
        parent::openSalesHistoryModal();

        $this->selectedHqSupplierIds = [];
    }

    /**
     * 外部発注候補生成の「発注先」一覧（EOS発注先・FAX発注先を1つにまとめたもの）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function hqExternalOrderContractors(): array
    {
        $eosContractorIds = collect($this->externalOrderJxContractorsData)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        return collect($this->externalOrderJxContractorsData)
            ->merge($this->externalOrderOtherContractorsData)
            ->map(fn (array $contractor): array => array_merge($contractor, [
                'is_eos' => $eosContractorIds->has((int) ($contractor['id'] ?? 0)),
            ]))
            ->unique('id')
            ->values()
            ->toArray();
    }

    /**
     * 外部発注候補生成の「仕入先」一覧と、発注先→仕入先の紐づけ。
     *
     * @return array{suppliers: array<int, array<string, mixed>>, links: array<string, array<int>>}
     */
    public function hqSupplierSelectionData(): array
    {
        if ($this->hqSupplierSelectionCache !== null) {
            return $this->hqSupplierSelectionCache;
        }

        $contractorIds = collect($this->externalOrderContractorsData)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $this->hqSupplierSelectionCache = $this->hqService()
            ->contractorSupplierLinks($this->hqWarehouseId(), $contractorIds);
    }

    /**
     * 外部発注候補リストの作成。
     *
     * 親の集計結果に対して、選択した仕入先での絞り込みと、店舗（敦賀・小浜）の
     * 卸実績の除外、本部用の参考データの追加を行う。
     */
    public function calculateSalesBasedExternalOrderPreview(): void
    {
        $contractorIds = collect($this->selectedExternalOrderContractorIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($contractorIds === []) {
            $this->failHqPreview('発注先を1件以上選択してください。');

            return;
        }

        $supplierIds = $this->effectiveHqSupplierIds($contractorIds);
        if ($supplierIds === []) {
            $this->failHqPreview('仕入先を1件以上選択してください。');

            return;
        }

        parent::calculateSalesBasedExternalOrderPreview();

        if ($this->salesBasedExternalOrderPreviewRows === []) {
            return;
        }

        $this->applyHqPreviewAdjustments($supplierIds);
    }

    /**
     * 登録リストの参考データ。未取得の行だけ取得して保持する（Blade から呼ぶ）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function registrationLineReferences(): array
    {
        $activeKeys = [];
        $missingItemIdsByWarehouse = [];

        foreach ($this->lines as $line) {
            $warehouseId = (int) ($line['warehouse_id'] ?? 0);
            $itemId = (int) ($line['item_id'] ?? 0);
            if ($warehouseId < 1 || $itemId < 1) {
                continue;
            }

            $key = "{$warehouseId}:{$itemId}";
            $activeKeys[$key] = true;

            // 項目を追加する前に保持した参考データ（volume_label なし）は取り直す。
            if (! isset($this->lineReferences[$key]) || ! array_key_exists('volume_label', $this->lineReferences[$key])) {
                $missingItemIdsByWarehouse[$warehouseId][$itemId] = $itemId;
            }
        }

        $this->lineReferences = array_intersect_key($this->lineReferences, $activeKeys);

        foreach ($missingItemIdsByWarehouse as $warehouseId => $itemIds) {
            $references = $this->hqService()->itemReferences((int) $warehouseId, array_values($itemIds));

            foreach ($references as $itemId => $reference) {
                $this->lineReferences["{$warehouseId}:{$itemId}"] = $reference;
            }
        }

        return $this->lineReferences;
    }

    public function refreshLineReferences(): void
    {
        $this->lineReferences = [];

        Notification::make()
            ->title('参考データを更新しました')
            ->success()
            ->send();
    }

    /**
     * 登録リストの発注先プルダウンからの変更。変更処理そのものは親と同じ。
     */
    public function applyLineContractorChange(
        int $index,
        int $contractorId,
        ?int $supplierId = null,
        ?int $itemContractorWarehouseId = null,
    ): void {
        if (! isset($this->lines[$index])) {
            $this->warnHq('変更対象の明細が見つかりません。');

            return;
        }

        // 同じ発注先・仕入先を選び直しただけなら何もしない（入荷予定日などを初期値に戻さない）。
        $line = $this->lines[$index];
        $sameContractor = (int) ($line['contractor_id'] ?? 0) === $contractorId;
        $sameSupplier = (int) ($supplierId ?? 0) < 1 || (int) ($line['supplier_id'] ?? 0) === (int) $supplierId;
        if ($sameContractor && $sameSupplier) {
            return;
        }

        $this->contractorChangeLineIndex = $index;

        try {
            $this->applyContractorChange($contractorId, $supplierId, $itemContractorWarehouseId);
        } finally {
            $this->contractorChangeLineIndex = null;
        }
    }

    /**
     * 選択中の仕入先のうち、選択中の発注先に紐づくものだけを返す。
     *
     * @param  array<int>  $contractorIds
     * @return array<int>
     */
    private function effectiveHqSupplierIds(array $contractorIds): array
    {
        $links = $this->hqSupplierSelectionData()['links'] ?? [];
        $linkedSupplierIds = [];

        foreach ($contractorIds as $contractorId) {
            foreach ($links[(string) $contractorId] ?? [] as $supplierId) {
                $linkedSupplierIds[(int) $supplierId] = true;
            }
        }

        return collect($this->selectedHqSupplierIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => isset($linkedSupplierIds[$id]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int>  $supplierIds
     */
    private function applyHqPreviewAdjustments(array $supplierIds): void
    {
        $service = $this->hqService();
        $warehouseId = $this->hqWarehouseId();
        $conditions = $this->salesBasedExternalOrderPreviewConditions;
        $supplierIdSet = array_flip($supplierIds);

        $rows = array_values(array_filter(
            $this->salesBasedExternalOrderPreviewRows,
            fn (array $row): bool => isset($supplierIdSet[(int) ($row['supplier_id'] ?? 0)]),
        ));
        $itemIds = collect($rows)
            ->pluck('item_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $startDate = (string) ($conditions['sales_start_date'] ?? '');
        $endDate = (string) ($conditions['sales_end_date'] ?? '');
        $days = max(1, (int) ($conditions['days'] ?? 1));
        $branchQuantities = ($startDate !== '' && $endDate !== '')
            ? $service->branchWholesaleQuantities($warehouseId, $itemIds, [
                'period' => ['start' => $startDate, 'end' => $endDate],
            ])
            : [];
        $references = $service->rowReferences($warehouseId, $itemIds);

        $adjustedRows = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $branchQuantity = (int) ($branchQuantities[$itemId]['period'] ?? 0);
            $salesQuantity = HqOrderRegistrationReferenceService::subtractBranchQuantity(
                (int) ($row['sales_qty'] ?? 0),
                $branchQuantity,
            );

            // 店舗卸を除くと本部倉庫からの出荷実績が無い商品は候補にしない。
            if ($salesQuantity <= 0) {
                continue;
            }

            $adjustedRows[] = array_merge($row, $references[$itemId] ?? [], [
                'sales_qty' => $salesQuantity,
                'excluded_branch_sales_qty' => $branchQuantity,
                'daily_avg_qty' => round($salesQuantity / $days, 2),
                'order_piece_qty' => max($salesQuantity - (int) ($row['projected_stock'] ?? 0), 0),
            ]);
        }

        if ($adjustedRows === []) {
            $this->failHqPreview('現在の条件に該当する候補がありません。');
            $this->showSalesBasedExternalOrderPreviewModal = false;
            $this->showSalesHistoryModal = true;

            return;
        }

        $this->salesBasedExternalOrderPreviewRows = $adjustedRows;
        $this->salesBasedExternalOrderPreviewConditions = array_merge($conditions, [
            'expected_arrival_date' => $this->earliestHqExpectedArrivalDate($adjustedRows)
                ?? ($conditions['expected_arrival_date'] ?? null),
            'supplier_count' => count($supplierIds),
            'sales_exclusion_label' => $service->salesExclusionLabel(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function earliestHqExpectedArrivalDate(array $rows): ?string
    {
        return collect($rows)
            ->pluck('default_expected_arrival_date')
            ->filter()
            ->map(function ($date): ?string {
                try {
                    return Carbon::parse((string) $date)->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->sort()
            ->first();
    }

    private function failHqPreview(string $message): void
    {
        $this->resetSalesBasedExternalOrderPreview();
        $this->salesBasedExternalOrderPreviewError = $message;
        $this->warnHq($message);
    }

    private function warnHq(string $message): void
    {
        Notification::make()
            ->title($message)
            ->warning()
            ->send();
    }

    private function hqService(): HqOrderRegistrationReferenceService
    {
        return $this->hqReferenceService ??= app(HqOrderRegistrationReferenceService::class);
    }
}
