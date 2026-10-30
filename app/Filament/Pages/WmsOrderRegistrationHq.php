<?php

namespace App\Filament\Pages;

use App\Enums\AutoOrder\OrderChannel;
use App\Enums\EMenu;
use App\Enums\QuantityType;
use App\Models\Sakemaru\Item;
use App\Services\AutoOrder\ContractorOrderConditions;
use App\Services\AutoOrder\HqOrderRegistrationReferenceService;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use App\Support\OrderArrivalDate;
use BackedEnum;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * 外部発注（本部） — 本部 91 番倉庫専用の発注登録画面。
 *
 * 既存の（新）外部発注（WmsOrderRegistration）を継承し、本部向けの違いだけをここに書く。
 * 共通の選択処理を継承し、発注確定（登録・入荷予定・PDF 生成）は親の処理を使う。
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

    public string $bulkOrderChannel = 'FAX';

    public bool $showBulkContractorChangeModal = false;

    public string $bulkContractorSearch = '';

    public array $bulkContractorRows = [];

    #[Locked]
    public string $bulkContractorSelectionHash = '';

    public function openBulkContractorChangeModal(): void
    {
        if ($this->selectedVisibleRegistrationLineIndexes() === []) {
            $this->warnHq('発注先を変更する登録リスト行を選択してください。');

            return;
        }

        $this->bulkContractorSearch = '';
        $this->bulkContractorSelectionHash = $this->currentBulkContractorSelectionHash();
        $this->showBulkContractorChangeModal = true;
        $this->searchBulkContractorChangeCandidates();
    }

    public function closeBulkContractorChangeModal(): void
    {
        $this->showBulkContractorChangeModal = false;
        $this->bulkContractorSearch = '';
        $this->bulkContractorRows = [];
        $this->bulkContractorSelectionHash = '';
    }

    public function searchBulkContractorChangeCandidates(): void
    {
        if (! $this->showBulkContractorChangeModal) {
            return;
        }

        $search = trim(mb_convert_kana($this->bulkContractorSearch, 'as'));
        $this->bulkContractorRows = DB::connection('sakemaru')->table('contractors')
            ->where('client_id', config('client.id', config('app.client_id')))
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('code', 'like', $search.'%')->orWhere('name', 'like', '%'.$search.'%');
            }))
            ->orderBy('code')->limit(50)->get(['id', 'code', 'name'])
            ->map(fn ($row): array => (array) $row)->all();
    }

    public function applyBulkContractorChange(int $contractorId): void
    {
        if (! $this->showBulkContractorChangeModal || $this->bulkContractorSelectionHash === '') {
            return;
        }

        $indexes = $this->selectedVisibleRegistrationLineIndexes();
        if ($indexes === [] || ! hash_equals($this->bulkContractorSelectionHash, $this->currentBulkContractorSelectionHash())) {
            $this->warnHq('選択行や明細が変更されています。一度閉じて、変更する行を選び直してください。');

            return;
        }

        $contractor = DB::connection('sakemaru')->table('contractors')->where('id', $contractorId)
            ->where('client_id', config('client.id', config('app.client_id')))->where('is_active', true)->first(['code']);
        if (! $contractor) {
            $this->warnHq('有効な発注先が見つかりません。発注先を検索し直してください。');

            return;
        }

        $changes = [];
        $errors = [];
        $faxCount = 0;
        $optionsByItem = [];
        foreach ($indexes as $index) {
            $line = $this->lines[$index];
            if ((int) ($line['contractor_id'] ?? 0) === $contractorId) {
                continue;
            }

            $label = '['.($line['item_code'] ?? '').']'.($line['item_name'] ?? '');
            // 個別変更と同じ91倉庫優先の候補から、商品ごとの仕入先を決める。
            $itemId = (int) ($line['item_id'] ?? 0);
            $options = $optionsByItem[$itemId] ??= collect(parent::searchCandidateContractorChangeOptions($itemId, (string) $contractor->code))
                ->where('contractor_id', $contractorId)->filter(fn (array $row): bool => (bool) ($row['is_selectable'] ?? false))
                ->unique('supplier_id')->values();
            if ($options->count() !== 1) {
                $errors[] = $label.($options->isEmpty() ? '：仕入先を決定できません。' : '：仕入先が複数あります。個別に発注先を変更してください。');

                continue;
            }

            $option = $options->first();
            try {
                $changes[$index] = $this->lineWithContractorChange($line, $contractorId, (int) $option['supplier_id'], $option['item_contractor_warehouse_id']);
                if ((int) $changes[$index]['supplier_id'] !== (int) $option['supplier_id'] || (($option['is_item_linked'] ?? false) && ! $changes[$index]['item_contractor_linked'])) {
                    throw new \InvalidArgumentException('発注先設定が変更されています。検索し直してください。');
                }
                if (($line['order_channel'] ?? '') === OrderChannel::EOS->value && $changes[$index]['order_channel'] === OrderChannel::FAX->value) {
                    $faxCount++;
                }
            } catch (\InvalidArgumentException $e) {
                $errors[] = $label.'：'.$e->getMessage();
            }
        }

        if ($errors !== []) {
            $this->warnHq("発注先を一括変更できません。明細は変更していません。\n".implode("\n", $errors));

            return;
        }

        // 全明細の確認に成功するまで、登録リストへ反映しない。
        foreach ($changes as $index => $line) {
            $this->lines[$index] = $line;
        }
        if ($this->lineContractorFilter !== '' && ! collect($this->lines)->contains(fn (array $line): bool => (string) ($line['contractor_id'] ?? '') === $this->lineContractorFilter)) {
            $this->lineContractorFilter = '';
        }
        $this->clearRegistrationLineSelection();
        $this->closeBulkContractorChangeModal();

        Notification::make()->title('発注先を一括変更しました')
            ->body(number_format(count($changes)).'件を変更しました。'.($faxCount > 0 ? 'うち'.number_format($faxCount).'件は変更先でEOS発注できないため、FAX発注に変更しました。' : ''))
            ->success()->send();
    }

    private function currentBulkContractorSelectionHash(): string
    {
        $lines = array_intersect_key($this->lines, array_flip($this->selectedVisibleRegistrationLineIndexes()));

        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }

    /** @var array<int, int> */
    protected array $hqItemCaseCapacities = [];

    protected function purchasePriceForLine(int $itemId, int $supplierPartnerId, int $warehouseId, QuantityType $quantityType): array
    {
        if ($quantityType === QuantityType::CASE && $itemId > 0) {
            $capacityCase = $this->hqItemCaseCapacities[$itemId] ??= (int) Item::query()
                ->whereKey($itemId)->value('capacity_case');

            // 入数1はケース発注のまま、単価だけバラ側を参照する。
            if ($capacityCase === 1) {
                $quantityType = QuantityType::PIECE;
            }
        }

        return parent::purchasePriceForLine($itemId, $supplierPartnerId, $warehouseId, $quantityType);
    }

    public function applyBulkOrderChannel(): void
    {
        $channel = OrderChannel::tryFrom($this->bulkOrderChannel);
        $indexes = $this->selectedVisibleRegistrationLineIndexes();
        if (! $channel || $indexes === []) {
            $this->warnHq('一括変更する発注区分と登録リスト行を選択してください。');

            return;
        }
        $jxIds = array_flip(app(OrderRegistrationSearchService::class)->jxContractorIds());
        $invalid = [];
        foreach ($indexes as $index) {
            $line = $this->lines[$index];
            if ($channel === OrderChannel::EOS && (! ($line['item_contractor_linked'] ?? true) || ! isset($jxIds[(int) ($line['contractor_id'] ?? 0)]))) {
                $invalid[] = '['.($line['item_code'] ?? '').']'.($line['item_name'] ?? '');
            }
        }
        if ($invalid !== []) {
            $this->warnHq('EOS発注できない商品があります。選択を外すかFAX発注を指定してください。'.implode('、', $invalid));

            return;
        }
        $changed = 0;
        foreach ($indexes as $index) {
            if (($this->lines[$index]['order_channel'] ?? '') !== $channel->value) {
                $this->lines[$index]['order_channel'] = $channel->value;
                $this->lines[$index]['order_channel_label'] = $channel->label();
                $changed++;
            }
        }
        $this->clearRegistrationLineSelection();
        Notification::make()->title('発注区分を変更しました')->body(number_format($changed).'件')->success()->send();
    }

    public function applyBulkExpectedArrivalDate(): void
    {
        try {
            $this->bulkExpectedArrivalDate = OrderArrivalDate::normalizeForOrder($this->bulkExpectedArrivalDate);
        } catch (\InvalidArgumentException $e) {
            $this->warnHq($e->getMessage());

            return;
        }
        parent::applyBulkExpectedArrivalDate();
    }

    public function confirmOrders(): void
    {
        foreach ($this->lines as $index => $line) {
            try {
                $date = OrderArrivalDate::normalizeForOrder((string) ($line['expected_arrival_date'] ?? ''));
                $this->lines[$index]['expected_arrival_date'] = $date;
            } catch (\InvalidArgumentException $e) {
                $this->warnHq('['.($line['item_code'] ?? '').'] '.$e->getMessage());

                return;
            }
        }
        parent::confirmOrders();
    }

    public function registrationLineDetails(): array
    {
        $conditions = app(ContractorOrderConditions::class)->attach($this->lines);
        $result = [];
        $locations = [];
        $warehouseIds = [];
        foreach ($this->lines as $line) {
            $warehouseIds[] = (int) ($line['item_contractor_warehouse_id'] ?? $line['warehouse_id'] ?? 0);
        }
        $settings = DB::connection('sakemaru')->table('item_contractors')->whereIn('item_id', array_column($this->lines, 'item_id'))
            ->whereIn('warehouse_id', $warehouseIds)->get(['warehouse_id', 'item_id', 'contractor_id', 'supplier_id', 'note']);
        foreach ($this->lines as $index => $line) {
            $warehouseId = (int) ($line['item_contractor_warehouse_id'] ?? $line['warehouse_id'] ?? 0);
            $locations[$warehouseId] ??= $this->defaultLocationCodes($warehouseId, array_column($this->lines, 'item_id'));
            $setting = $settings->first(fn ($row): bool => (int) $row->warehouse_id === $warehouseId && (int) $row->item_id === (int) $line['item_id']
                && (int) $row->contractor_id === (int) $line['contractor_id'] && (int) $row->supplier_id === (int) ($line['supplier_id'] ?? 0));
            $result[$index] = [
                'default_location_code' => $locations[$warehouseId][(int) $line['item_id']] ?? '',
                'item_contractor_note' => (string) ($setting?->note ?? ''),
                'contractor_order_conditions' => $conditions[$index]['contractor_order_conditions'] ?? '',
                'supplier_order_conditions' => $conditions[$index]['supplier_order_conditions'] ?? '',
            ];
        }

        return $result;
    }

    public function searchCandidateContractorChangeOptions(int $itemId, ?string $search = null): array
    {
        return app(ContractorOrderConditions::class)->attach(parent::searchCandidateContractorChangeOptions($itemId, $search));
    }

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
        $scope = '売上・小売と波動由来の移動を集計し、手入力の移動は含みません。';
        $scope .= $exclusionLabel !== '' ? "{$exclusionLabel}の卸実績（計上は本部、出荷は店舗）は含みません。" : '';

        return array_map(
            fn (string $help): string => $help.$scope,
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

    protected function shouldLoadBaseWeeklySalesQuantities(): bool
    {
        // 検索・候補表示とも、親の結果に店舗卸を除いた週実績を後から一括で付与する。
        return false;
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

        $result['data'] = app(ContractorOrderConditions::class)->attach($result['data']);

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

        $this->salesBasedExternalOrderPreviewRows = app(ContractorOrderConditions::class)->attach($adjustedRows);
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
