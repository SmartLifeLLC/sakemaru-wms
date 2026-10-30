<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use App\Filament\Support\AdminPage;
use App\Jobs\ProcessAutoReplenishmentRun;
use App\Models\WmsAutoOrderRun;
use App\Models\WmsAutoOrderRunLine;
use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\ExecutionService;
use App\Services\AutoReplenishment\HistoryService;
use App\Services\AutoReplenishment\PalletUnitService;
use App\Services\AutoReplenishment\PolicyService;
use App\Services\AutoReplenishment\PolicySimulationService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SettingsRecommendationService;
use App\Services\AutoReplenishment\ShippingQuantityService;
use App\Services\AutoReplenishment\ShippingQuantitySet;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Sakemaru\Auth\Services\PermissionService;

abstract class AutoReplenishmentPage extends AdminPage
{
    use \Livewire\WithFileUploads;
    use WithPagination;

    private const EDITOR_ROUTE_FIELDS = ['item_id', 'channel', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id'];

    protected string $view = 'filament.pages.auto-replenishment';

    protected static string $permissionResource = 'auto-replenishment-settings';

    protected static string $screen = 'settings';

    #[Locked]
    public ?int $warehouseId = null;

    #[Url]
    public string $search = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $historyView = 'lines';

    #[Url]
    public ?int $historyRunId = null;

    #[Locked]
    public ?int $runId = null;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $detailId = null;

    public bool $showEditor = false;

    public bool $showWarehouseEditor = false;

    public array $form = [];

    #[Locked]
    public array $editorRoute = [];

    public array $warehouseForm = [];

    public string $abortReason = '';

    public string $itemSearch = '';

    public string $itemJanSearch = '';

    #[Url]
    public string $janSearch = '';

    public bool $showItemPicker = false;

    public $csvFile;

    public bool $showCsv = false;

    #[Locked]
    public array $csvPreview = [];

    #[Locked]
    public array $selectedVersions = [];

    #[Locked]
    public ?array $confirmation = null;

    #[Locked]
    public ?int $testingId = null;

    #[Locked]
    public array $testContext = [];

    #[Locked]
    public ?array $testResult = null;

    #[Locked]
    public ?array $recommendation = null;

    #[Locked]
    public ?array $recommendationContext = null;

    #[Locked]
    public ?array $inventoryProjectionInputs = null;

    #[Locked]
    public ?array $inventoryProjection = null;

    #[Locked]
    public ?array $inventoryProjectionContext = null;

    #[Locked]
    public ?string $inventoryProjectionError = null;

    public string $recommendationUnitMode = 'AUTO';

    public string $recommendationReviewDays = '7';

    public string $recommendationSafetyDays = '5';

    #[Locked]
    public string $appliedRecommendationSafetyDays = '5';

    #[Locked]
    public bool $recommendationPreferencesChanged = false;

    /** この商品のイベント（settings editor）: ticks per standard type, display info, and whether freshly learned candidates are previewed. */
    public array $eventSetup = [];

    public array $eventInfo = [];

    public bool $eventCandidatesPreview = false;

    /** 詳細設定 sub-dialog of the editor (発注単位・発注数量・イベント・発注方法・発注先). */
    public bool $showAdvanced = false;

    #[Locked]
    public ?array $palletUnit = null;

    public string $palletPieces = '';

    #[Locked]
    public ?array $shippingProfile = null;

    public string $shippingQuantities = '[]';

    public function getTitle(): string
    {
        return '';
    }

    public function getHeading(): string
    {
        return '';
    }

    public static function getNavigationGroup(): ?string
    {
        return EMenuCategory::AUTO_REPLENISHMENT->label();
    }

    public function mount(): void
    {
        $this->warehouseId = $this->selectedWarehouseId();
        $this->resetPage();
        if ($this->historyRunId && ! WmsAutoOrderRun::where(['client_id' => $this->clientId(), 'warehouse_id' => $this->warehouseId, 'is_preview' => false])->whereKey($this->historyRunId)->exists()) {
            $this->historyRunId = null;
        }
    }

    private function selectedWarehouseId(): ?int
    {
        return auth()->user()?->fresh()?->getSelectedWarehouseId();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedVersions = [];
    }

    public function updatedJanSearch(): void
    {
        $this->updatedSearch();
    }

    public function updatedChannel(): void
    {
        $this->resetPage();
        $this->selectedVersions = [];
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->selectedVersions = [];
    }

    public function updatedHistoryView(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryRunId(): void
    {
        $this->resetPage();
    }

    private function clientId(): int
    {
        abort_unless(static::canAccess(), 403);

        $clientId = app(ReplenishmentGuard::class)->clientId();
        abort_unless((int) auth()->user()->getAttribute('client_id') === $clientId, 403);
        abort_unless($this->warehouseId === $this->selectedWarehouseId(), 409, '選択倉庫が変更されました。ページを再読込してください。');

        return $clientId;
    }

    private function authorizeOperation(string $resource, string $action): int
    {
        $clientId = $this->clientId();
        abort_unless(app(PermissionService::class)->check(auth()->user(), "wms.{$resource}.{$action}"), 403);
        abort_unless(DB::connection('sakemaru')->table('warehouses')->where(['id' => $this->warehouseId, 'client_id' => $clientId, 'is_active' => 1, 'is_virtual' => 0])->exists(), 403);

        return $clientId;
    }

    private function checked(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                throw $e;
            }
            $business = get_class($e) === \RuntimeException::class || get_class($e) === \InvalidArgumentException::class;
            if (! $business) {
                report($e);
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => $business ? $e->getMessage() : '処理を完了できませんでした。再実行しても解消しない場合は管理者に連絡してください。']);
        }
    }

    public function editPolicy(?int $id = null): void
    {
        $this->clearInventoryProjection();
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        $this->resetValidation();
        $this->editingId = $id;
        $policy = $id ? WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $this->warehouseId])->findOrFail($id) : null;
        $this->form = $policy?->toArray() ?? ['channel' => 'EOS', 'strategy_key' => 'threshold_capped_v1'];
        $this->form['is_enabled'] = (bool) ($policy?->is_enabled ?? false);
        $this->form['order_unit_mode'] = $policy?->order_unit_mode ?? 'AUTO';
        $this->form['shipping_unit_mode'] = app(PalletUnitService::class)->binding($policy ? (int) $policy->id : null) ? 'PALLET' : 'STANDARD';
        if ($policy?->channel === 'LOGISTICS' && app(ShippingQuantityService::class)->binding((int) $policy->id)) {
            $this->form['shipping_unit_mode'] = 'SHIPMENT';
        }
        $this->recommendationUnitMode = $policy?->calculation_basis['unit_mode'] ?? ((int) $policy?->order_multiple_pieces === 1 ? 'PIECE' : 'AUTO');
        if ($this->recommendationUnitMode === 'PALLET') {
            $this->recommendationUnitMode = 'AUTO';
        }
        $this->recommendationReviewDays = (string) ($policy?->calculation_basis['review_days'] ?? 7);
        $this->recommendationSafetyDays = $this->appliedRecommendationSafetyDays = (string) ($policy?->calculation_basis['safety_stock_days'] ?? 5);
        $this->recommendationPreferencesChanged = false;
        $this->recommendation = $policy?->calculation_basis ? ['available' => empty($policy->calculation_basis['reason']), 'basis' => $policy->calculation_basis] : null;
        $this->recommendationContext = null;
        $this->itemSearch = '';
        $this->itemJanSearch = '';
        $this->showItemPicker = ! $policy;
        $this->showAdvanced = false;
        $this->showEditor = true;
        $this->loadPalletEditor();
        $this->loadEventSetup($clientId);
        $this->rememberEditorRoute();
    }

    /** Current application / factors / upcoming events of the editor's product (ticks = saved application). */
    private function loadEventSetup(int $clientId): void
    {
        $this->eventSetup = [];
        $this->eventInfo = [];
        $this->eventCandidatesPreview = false;
        $itemId = (int) ($this->form['item_id'] ?? 0);
        if ($itemId <= 0) {
            return;
        }
        $this->eventInfo = app(\App\Services\AutoReplenishment\ItemDemandEventService::class)->productSetup($clientId, $itemId);
        foreach ($this->eventInfo['types'] ?? [] as $type => $info) {
            $this->eventSetup[$type] = $info['applied'];
        }
    }

    /** Standard event types ticked for this product (what the recommendation previews and what saving applies). */
    private function tickedEventTypes(): array
    {
        return array_values(array_filter(array_keys(array_filter($this->eventSetup, fn ($v) => (bool) $v)), fn ($t) => in_array($t, \App\Services\AutoReplenishment\DemandEventCalendar::STANDARD_TYPES, true)));
    }

    public function updatedEventSetup(): void
    {
        if ($this->recommendation !== null) {
            $this->invalidateRecommendationPreferences();
        }
    }

    /**
     * Learn this product's event factors from its own past sales (progress is streamed to the editor), then pre-tick the
     * suggested events when the product has no application yet. First learning goes straight into use (nothing is replaced);
     * a re-learning is kept as a candidate until the operator saves the setting (explicit acceptance).
     */
    public function learnProductEvents(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings', 403);
        $this->checked(fn () => $this->learnProductEventsNow($clientId));
        if ($this->recommendation !== null) {
            $this->invalidateRecommendationPreferences();
        }
    }

    private function learnProductEventsNow(int $clientId): void
    {
        $itemId = (int) ($this->form['item_id'] ?? 0);
        throw_if($itemId <= 0, \RuntimeException::class, '商品を選択してください。');
        if (! ($this->eventInfo['installed'] ?? false)) {
            $this->loadEventSetup($clientId);
            throw_unless($this->eventInfo['installed'] ?? false, \RuntimeException::class, 'イベント設定のテーブルが未作成です。');
        }
        $stream = fn (string $line) => $this->stream(to: 'eventProgress', content: e($line).'<br>', replace: false);
        $stream('この商品の過去3年のイベント実績を読んでいます…');
        $learning = app(\App\Services\AutoReplenishment\DemandEventLearningService::class);
        $through = CarbonImmutable::now('Asia/Tokyo')->subDay()->toDateString();
        $profiles = $learning->learnProduct($clientId, $itemId, $through, (int) config('replenishment.demand_events.training_years', 3), $stream);
        throw_if($profiles === [], \RuntimeException::class, 'この商品のイベント倍率を学習できませんでした（過去のイベント期間に十分な販売実績がありません）。曜日別のみで算定します。');
        $hadActive = \App\Models\WmsDemandEventProfile::where(['client_id' => $clientId, 'scope_type' => 'ITEM', 'scope_id' => $itemId, 'stage' => \App\Models\WmsDemandEventProfile::ACTIVE])->exists();
        $stage = $hadActive ? \App\Models\WmsDemandEventProfile::CANDIDATE : \App\Models\WmsDemandEventProfile::ACTIVE;
        $learning->persistItem($clientId, $itemId, $profiles, $through, $stage);
        $hadApplication = \App\Models\WmsItemDemandEvent::where(['client_id' => $clientId, 'item_id' => $itemId])->exists();
        $this->loadEventSetup($clientId);
        $this->eventCandidatesPreview = $hadActive;
        if (! $hadApplication) {
            foreach ($this->eventInfo['types'] as $type => $info) {
                $this->eventSetup[$type] = $info['suggest'];
            }
        }
        $summary = [];
        foreach ($this->eventInfo['types'] as $type => $info) {
            $summary[] = $info['label'].' '.($info['mean'] !== null ? number_format($info['mean'], 2).'倍／'.$info['instances'].'年' : '実績なし');
        }
        $stream('学習しました：'.implode('、', $summary).($hadActive ? '（候補。この設定を保存すると有効になります）' : ''));
    }

    /** Save the product's event application and accept the previewed factors; called from savePolicy after the quantities are saved. */
    private function saveEventSetup(int $clientId, int $itemId): void
    {
        if (! ($this->eventInfo['installed'] ?? false)) {
            return;
        }
        $types = $this->tickedEventTypes();
        $before = array_keys(array_filter(array_map(fn ($i) => $i['applied'], $this->eventInfo['types'] ?? [])));
        sort($before);
        $after = $types;
        sort($after);
        if ($before !== $after) {
            $this->authorizeOperation('auto-replenishment-settings', 'enable'); // changing what adjusts the quantities: same permission as approval
            app(\App\Services\AutoReplenishment\ItemDemandEventService::class)->apply($clientId, [$itemId => $types], (int) auth()->id(), '商品設定画面から');
        }
        if ($this->eventCandidatesPreview && $types) {
            app(\App\Services\AutoReplenishment\DemandEventLearningService::class)->promoteItem($clientId, $itemId, $types);
        }
    }

    private function rememberEditorRoute(): void
    {
        $this->editorRoute = array_intersect_key($this->form, array_flip(self::EDITOR_ROUTE_FIELDS));
    }

    private function assertEditorRoute(): void
    {
        if ($this->showEditor && $this->editorRoute !== []) {
            foreach (self::EDITOR_ROUTE_FIELDS as $field) {
                if ((string) ($this->form[$field] ?? '') !== (string) ($this->editorRoute[$field] ?? '')) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['operation' => '発注先・配送はこの画面では変更できません。商品設定を開き直してください。']);
                }
            }

            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages(['operation' => '商品設定を開き直してください。']);
    }

    public function selectItem(int $id): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && ! $this->editingId && static::$screen === 'settings', 403);
        $eligibility = app(\App\Services\AutoReplenishment\OrderRouteEligibility::class);
        $ids = array_unique(array_merge($eligibility->contractorIds('EOS'), $eligibility->contractorIds('LOGISTICS'), $eligibility->referenceContractorIds($clientId)));
        abort_unless($this->itemSelectionQuery($clientId, $ids)->where('items.id', $id)->exists(), 422);
        $this->showItemPicker = false;
        if ((int) ($this->form['item_id'] ?? 0) === $id) {
            return;
        }
        $this->form['item_id'] = $id;
        $this->updatedForm($id, 'item_id');
        $this->loadEventSetup($clientId);
    }

    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'strategy_key' && $this->inventoryProjectionInputs !== null) {
            $this->refreshInventoryProjection();
        }
        if ($key === 'order_unit_mode') {
            $this->authorizeOperation('auto-replenishment-settings', 'edit');
            $this->assertEditorRoute();
            // Previously calculated quantities need a fresh basis. A manual-only
            // policy can keep its quantities; the selected unit is checked on save.
            if ($this->recommendation['available'] ?? false) {
                $this->invalidateRecommendationPreferences();
            } else {
                $this->clearInventoryProjection();
                $this->recommendation = null;
                $this->recommendationContext = null;
            }

            return;
        }
        if (! in_array($key, ['item_id', 'channel', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id', 'shipping_unit_mode'], true)) {
            return;
        }
        $client = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor, 403);
        if (in_array($key, self::EDITOR_ROUTE_FIELDS, true) && $key !== 'item_id') {
            $this->form = array_replace(array_diff_key($this->form, array_flip(self::EDITOR_ROUTE_FIELDS)), $this->editorRoute);
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => '発注先・配送はこの画面では変更できません。商品発注管理・移動配送コース設定を確認してください。']);
        }
        if ($key !== 'item_id') {
            $this->assertEditorRoute();
        }
        $hadRecommendation = $this->recommendation !== null;
        $this->clearInventoryProjection();
        $this->recommendation = null;
        $this->recommendationContext = null;
        if (! $this->editingId && $hadRecommendation) {
            foreach (['reorder_point_pieces', 'max_order_pieces', 'min_order_pieces', 'order_multiple_pieces', 'target_stock_pieces'] as $field) {
                unset($this->form[$field]);
            }
        }
        if ($key === 'item_id') {
            abort_if($this->editingId, 422);
            $saved = WmsItemReorderPolicy::where(['client_id' => $client, 'warehouse_id' => $this->warehouseId, 'item_id' => (int) $value])->first();
            if ($saved) {
                $this->editPolicy((int) $saved->id);

                return;
            }
            $this->form = ['item_id' => $value, 'channel' => 'EOS', 'strategy_key' => 'threshold_capped_v1', 'is_enabled' => false, 'shipping_unit_mode' => 'STANDARD', 'order_unit_mode' => 'AUTO'];
            $this->recommendationUnitMode = 'AUTO';
            $this->recommendationReviewDays = '7';
            $this->recommendationSafetyDays = $this->appliedRecommendationSafetyDays = '5';
            $this->recommendationPreferencesChanged = false;
        } elseif ($key === 'channel') {
            foreach (['contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id'] as $field) {
                unset($this->form[$field]);
            }
        } elseif ($key === 'contractor_id') {
            unset($this->form['supplier_id'], $this->form['source_warehouse_id'], $this->form['delivery_course_id']);
        } elseif ($key === 'source_warehouse_id') {
            unset($this->form['delivery_course_id']);
        }
        if (in_array($key, ['channel', 'source_warehouse_id'], true)) {
            $this->form['shipping_unit_mode'] = 'STANDARD';
        }
        $db = DB::connection('sakemaru');
        $eligibility = app(\App\Services\AutoReplenishment\OrderRouteEligibility::class);
        $ids = array_unique(array_merge($eligibility->contractorIds('EOS'), $eligibility->contractorIds('LOGISTICS'), $eligibility->referenceContractorIds($client)));
        $routes = $db->table('item_contractors as ic')->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->leftJoin('wms_contractor_settings as cs', 'cs.contractor_id', '=', 'c.id')
            ->where(['ic.client_id' => $client, 'ic.warehouse_id' => $this->warehouseId, 'ic.item_id' => $this->form['item_id'] ?? 0, 'c.client_id' => $client, 'c.is_active' => 1])
            ->whereIn('ic.contractor_id', $ids)->get(['ic.contractor_id', 'ic.supplier_id', 'cs.transmission_type', 'cs.supply_warehouse_id']);
        if ($key !== 'item_id') {
            $channelIds = ($this->form['channel'] ?? '') === 'FAX' ? $eligibility->referenceContractorIds($client) : $eligibility->contractorIds($this->form['channel'] ?? 'EOS');
            $routes = $routes->filter(fn ($r) => in_array((int) $r->contractor_id, $channelIds, true));
        } else {
            // Adding FAX references must not change an existing EOS/logistics default.
            $automaticIds = array_merge($eligibility->contractorIds('EOS'), $eligibility->contractorIds('LOGISTICS'));
            $automaticRoutes = $routes->filter(fn ($r) => in_array((int) $r->contractor_id, $automaticIds, true));
            if ($automaticRoutes->isNotEmpty()) {
                $routes = $automaticRoutes;
            }
        }
        if (! empty($this->form['contractor_id'])) {
            $routes = $routes->where('contractor_id', (int) $this->form['contractor_id']);
        }
        if ($routes->count() === 1) {
            $route = $routes->first();
            $this->form['channel'] = $route->transmission_type === 'INTERNAL' ? 'LOGISTICS'
                : (in_array((int) $route->contractor_id, $eligibility->contractorIds('EOS'), true) ? 'EOS' : 'FAX');
            $this->form['contractor_id'] = (int) $route->contractor_id;
            $this->form['supplier_id'] = (int) $route->supplier_id;
            if ($this->form['channel'] === 'LOGISTICS') {
                $this->form['source_warehouse_id'] ??= (int) $route->supply_warehouse_id;
                $courses = $db->table('warehouse_stock_transfer_delivery_courses')->where(['from_warehouse_id' => $this->form['source_warehouse_id'], 'to_warehouse_id' => $this->warehouseId])->pluck('delivery_course_id');
                if ($courses->count() === 1) {
                    $this->form['delivery_course_id'] = (int) $courses->first();
                }
            }
        }
        $this->loadPalletEditor();
        if ($key === 'item_id' && $this->shippingProfile && $this->form['channel'] === 'LOGISTICS') {
            $this->form['shipping_unit_mode'] = 'SHIPMENT';
        }
        if (($this->form['shipping_unit_mode'] ?? '') === 'SHIPMENT') {
            $this->form['strategy_key'] = 'arrival_adaptive_v1';
            if ($this->shippingProfile) {
                $this->form['order_multiple_pieces'] = app(ShippingQuantitySet::class)->gcd($this->shippingProfile['quantities']);
            }
        }
        if (($this->form['shipping_unit_mode'] ?? '') === 'PALLET' && $this->palletUnit) {
            $this->form['order_multiple_pieces'] = $this->palletUnit['pieces_per_pallet'];
        }
        $this->rememberEditorRoute();
        // Do not replace quantities of an existing policy when only changing its route.
        if (! $this->editingId && ! empty($this->form['contractor_id']) && ! empty($this->form['supplier_id'])
            && (in_array($this->form['channel'] ?? '', ['EOS', 'FAX'], true) || ! empty($this->form['delivery_course_id']))) {
            $this->calculateSettings();
        }
    }

    public function calculateSettings(): void
    {
        $client = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings', 403);
        $this->assertEditorRoute();
        $this->resetValidation();
        $this->clearInventoryProjection();
        $this->recommendation = null;
        $this->recommendationContext = null;
        try {
            // First time for this product: learn its event factors from its own past sales (progress streamed), so the
            // operator can set the events on this screen without a separate step. Failure to learn only means weekday-only.
            if (($this->eventInfo['installed'] ?? false) && (int) ($this->form['item_id'] ?? 0) > 0) {
                // Decided from the database, not from the component state, so a stale editor never re-learns a product that already has factors.
                $this->eventInfo = app(\App\Services\AutoReplenishment\ItemDemandEventService::class)->productSetup($client, (int) $this->form['item_id']);
                if (! ($this->eventInfo['has_profiles'] ?? false)) {
                    try {
                        $this->learnProductEventsNow($client);
                    } catch (\RuntimeException $e) {
                        $this->stream(to: 'eventProgress', content: e($e->getMessage()).'<br>', replace: false);
                    }
                }
            }
            $options = ['unit_mode' => $this->recommendationUnitMode, 'review_days' => $this->recommendationReviewDays,
                'safety_stock_days' => $this->recommendationSafetyDays, 'existing_reorder_point' => $this->form['reorder_point_pieces'] ?? null];
            if ($this->eventInfo['installed'] ?? false) {
                // The events as ticked on this screen (saved or not), so the two-tier result shows what saving would do.
                $options['demand_events'] = app(\App\Services\AutoReplenishment\DemandEventRepository::class)->recommendationOptionsFor($client, (int) ($this->form['item_id'] ?? 0), $this->tickedEventTypes(), $this->eventCandidatesPreview);
            }
            $this->recommendation = app(SettingsRecommendationService::class)->recommend($client, (int) $this->warehouseId, $this->form, $options);
        } catch (\Throwable $e) {
            $business = get_class($e) === \RuntimeException::class || get_class($e) === \InvalidArgumentException::class;
            if (! $business) {
                report($e);
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => $business ? $e->getMessage() : '実績からの算定に失敗しました。管理者に確認してください。']);
        }
        $this->recommendationContext = $this->currentRecommendationContext();
        if ($this->recommendation['available']) {
            $this->form = array_replace($this->form, $this->recommendation['values']);
            $this->appliedRecommendationSafetyDays = $this->recommendationSafetyDays;
            $this->recommendationPreferencesChanged = false;
        }
        if (isset($this->recommendation['basis']['shipping_profile'])) {
            $this->shippingProfile = $this->recommendation['basis']['shipping_profile'];
            $this->shippingProfile['quantities'] = app(ShippingQuantitySet::class)->normalize($this->shippingProfile['quantities']);
            $this->shippingQuantities = json_encode($this->shippingProfile['quantities']);
        }
        if (isset($this->recommendation['basis']['pallet_unit'])) {
            $this->palletUnit = $this->recommendation['basis']['pallet_unit'];
            $this->palletPieces = (string) $this->palletUnit['pieces_per_pallet'];
        }
        $this->refreshInventoryProjection(true);
    }

    public function updatedRecommendationUnitMode(): void
    {
        $this->invalidateRecommendationPreferences();
    }

    public function updatedRecommendationSafetyDays(): void
    {
        $this->invalidateRecommendationPreferences();
    }

    public function updatedRecommendationReviewDays(): void
    {
        $this->invalidateRecommendationPreferences();
    }

    private function invalidateRecommendationPreferences(): void
    {
        $this->recommendationPreferencesChanged = true;
        $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings', 403);
        $this->clearInventoryProjection();
        $this->recommendation = null;
        $this->recommendationContext = null;
    }

    private function currentRecommendationContext(): array
    {
        $context = ['warehouse_id' => (string) $this->warehouseId,
            'unit_mode' => $this->recommendationUnitMode, 'review_days' => $this->recommendationReviewDays,
            'safety_stock_days' => $this->recommendationSafetyDays];
        foreach (['item_id', 'channel', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id', 'shipping_unit_mode', 'order_unit_mode'] as $field) {
            $context[$field] = (string) ($this->form[$field] ?? '');
        }
        $context['demand_events'] = implode(',', $this->tickedEventTypes()).($this->eventCandidatesPreview ? '|candidates' : '');

        return $context;
    }

    public function selectRecommendationCandidate(string $key): void
    {
        $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings', 403);
        $this->resetValidation();
        if ($this->recommendationContext === null || $this->recommendationContext !== $this->currentRecommendationContext()) {
            $this->clearInventoryProjection();
            $this->recommendation = null;
            $this->recommendationContext = null;
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => '算定条件が変わりました。「おすすめ設定」で計算し直してください。']);
        }
        // Candidates are kept in Locked state; the browser sends only the selected key.
        $candidate = in_array($key, ['sales', 'history'], true) ? ($this->recommendation['candidates'][$key] ?? null) : null;
        if (! $candidate || empty($candidate['available']) || empty($candidate['values']) || empty($candidate['basis'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => $candidate['basis']['reason'] ?? 'この候補は入力できません。「おすすめ設定」で計算し直してください。']);
        }
        $fields = array_flip(['strategy_key', 'reorder_point_pieces', 'min_order_pieces', 'max_order_pieces', 'order_multiple_pieces', 'target_stock_pieces']);
        $this->form = array_replace($this->form, array_intersect_key($candidate['values'], $fields));
        $this->recommendation = array_replace($this->recommendation, $candidate);
        $this->refreshInventoryProjection();
    }

    private function clearInventoryProjection(): void
    {
        $this->inventoryProjectionInputs = $this->inventoryProjection = $this->inventoryProjectionContext = null;
        $this->inventoryProjectionError = null;
    }

    public function refreshInventoryProjection(bool $reload = false): void
    {
        $client = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless(static::$screen === 'settings', 403);
        // Livewire may bundle a pending refresh with the editor's close update.
        if (! $this->showEditor) {
            return;
        }
        $this->assertEditorRoute();
        $this->inventoryProjection = null;
        $this->inventoryProjectionError = null;
        try {
            throw_if($this->recommendationPreferencesChanged, \RuntimeException::class, '算定条件が変わりました。「おすすめ設定」で計算し直してください。');
            $context = $this->currentRecommendationContext();
            if ($reload || $this->inventoryProjectionInputs === null || $this->inventoryProjectionContext !== $context) {
                $this->inventoryProjectionInputs = null;
                $this->inventoryProjectionInputs = app(\App\Services\AutoReplenishment\InventoryProjectionInputs::class)
                    ->read($client, (int) $this->warehouseId, $this->form, $this->recommendation['basis'] ?? null);
                $this->inventoryProjectionContext = $context;
            }
            $this->inventoryProjection = app(\App\Services\AutoReplenishment\InventoryProjection::class)->calculate(
                array_replace($this->form, ['calculation_basis' => $this->recommendation['basis'] ?? null]), $this->inventoryProjectionInputs);
        } catch (\Throwable $e) {
            $business = get_class($e) === \RuntimeException::class || get_class($e) === \InvalidArgumentException::class;
            if (! $business) {
                report($e);
            }
            $this->inventoryProjectionError = $business ? $e->getMessage() : '在庫推移を計算できませんでした。再取得しても解消しない場合は管理者に連絡してください。';
        }
    }

    private function loadPalletEditor(): void
    {
        $this->palletUnit = ($this->form['channel'] ?? '') === 'LOGISTICS' && ! empty($this->form['source_warehouse_id']) && ! empty($this->form['item_id'])
            ? (array) app(PalletUnitService::class)->find($this->clientId(), (int) $this->form['source_warehouse_id'], (int) $this->form['item_id']) : null;
        $this->palletUnit = $this->palletUnit ?: null;
        $this->palletPieces = (string) ($this->palletUnit['pieces_per_pallet'] ?? '');
        $service = app(ShippingQuantityService::class);
        $profile = ($this->form['channel'] ?? '') === 'LOGISTICS' && ! empty($this->form['source_warehouse_id']) && ! empty($this->form['item_id'])
            ? $service->find($this->clientId(), (int) $this->form['source_warehouse_id'], (int) $this->form['item_id']) : null;
        $this->shippingProfile = $profile ? (array) $profile : null;
        if ($profile) {
            $this->shippingProfile['quantities'] = $service->quantities($profile);
        }
        $this->shippingQuantities = json_encode($this->shippingProfile['quantities'] ?? []);
    }

    public function saveShippingQuantities(): void
    {
        $client = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings' && ($this->form['channel'] ?? '') === 'LOGISTICS', 403);
        $this->assertEditorRoute();
        $this->resetValidation();
        $this->checked(fn () => app(ShippingQuantityService::class)->save($client,
            (int) ($this->form['source_warehouse_id'] ?? 0), (int) ($this->form['item_id'] ?? 0), $this->shippingQuantities,
            (int) ($this->shippingProfile['version'] ?? 0), (int) auth()->id()));
        $this->loadPalletEditor();
        $this->form['shipping_unit_mode'] = 'SHIPMENT';
        $this->form['strategy_key'] = 'arrival_adaptive_v1';
        $this->form['order_multiple_pieces'] = app(ShippingQuantitySet::class)->gcd($this->shippingProfile['quantities']);
        $this->clearInventoryProjection();
        $this->recommendation = $this->recommendationContext = null;
        Notification::make()->success()->title('共有の出荷数量候補を保存しました。商品設定は「おすすめ設定」で確認して保存してください。')->send();
    }

    public function registerShippingHistory(): void
    {
        $client = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings' && ($this->form['channel'] ?? '') === 'LOGISTICS', 403);
        $this->assertEditorRoute();
        $this->resetValidation();
        $this->checked(function () use ($client) {
            $history = app(\App\Services\AutoReplenishment\SettingsRecommendationHistory::class)->read($client, (int) $this->warehouseId, $this->form);
            $profile = app(ShippingQuantitySet::class)->fromReceipts($history['receipts'], $history['period']);
            app(ShippingQuantityService::class)->save($client, (int) $this->form['source_warehouse_id'], (int) $this->form['item_id'],
                $profile['quantities'], (int) ($this->shippingProfile['version'] ?? 0), (int) auth()->id(), $profile['basis']);
        });
        $this->loadPalletEditor();
        $this->form['shipping_unit_mode'] = 'SHIPMENT';
        $this->form['strategy_key'] = 'arrival_adaptive_v1';
        $this->clearInventoryProjection();
        $this->recommendation = $this->recommendationContext = null;
        Notification::make()->success()->title('実出庫から共有候補を登録しました。「おすすめ設定」で店舗の数量条件を確認してください。')->send();
    }

    public function savePalletUnit(): void
    {
        $client = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        abort_unless($this->showEditor && static::$screen === 'settings' && ($this->form['channel'] ?? '') === 'LOGISTICS', 403);
        $this->assertEditorRoute();
        $this->resetValidation();
        $this->palletUnit = (array) $this->checked(fn () => app(PalletUnitService::class)->save($client,
            (int) ($this->form['source_warehouse_id'] ?? 0), (int) ($this->form['item_id'] ?? 0), $this->palletPieces,
            (int) ($this->palletUnit['version'] ?? 0), (int) auth()->id()));
        $this->palletPieces = (string) $this->palletUnit['pieces_per_pallet'];
        if (($this->form['shipping_unit_mode'] ?? '') === 'PALLET') {
            $this->form['order_multiple_pieces'] = $this->palletUnit['pieces_per_pallet'];
        }
        $this->clearInventoryProjection();
        $this->recommendation = null;
        $this->recommendationContext = null;
        Notification::make()->success()->title('共有パレット入数を保存しました。商品設定は「設定を保存」で反映します。')->send();
    }

    public function openPolicyTest(int $id): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'view');
        abort_unless(static::$screen === 'settings', 403);
        $this->resetValidation();
        $this->testResult = null;
        $this->testContext = $this->policyTestContext($clientId, $id);
        $this->testingId = $id;
    }

    public function runPolicyTest(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'view');
        abort_unless(static::$screen === 'settings' && $this->testingId, 403);
        $this->resetValidation();
        $this->testResult = null;
        // Re-read saved settings on every click; never calculate from browser-supplied values.
        $policy = WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $this->warehouseId])->findOrFail($this->testingId);
        $this->testContext = $this->policyTestContext($clientId, $this->testingId);
        $this->testResult = app(PolicySimulationService::class)->simulate($policy);
    }

    public function closePolicyTest(): void
    {
        $this->testingId = null;
        $this->testContext = [];
        $this->testResult = null;
    }

    private function policyTestContext(int $clientId, int $id): array
    {
        $policy = WmsItemReorderPolicy::query()->leftJoin('items as i', 'i.id', '=', 'wms_item_reorder_policies.item_id')
            ->leftJoin('contractors as c', 'c.id', '=', 'wms_item_reorder_policies.contractor_id')
            ->leftJoin('warehouses as sw', 'sw.id', '=', 'wms_item_reorder_policies.source_warehouse_id')
            ->where('wms_item_reorder_policies.client_id', $clientId)->where('wms_item_reorder_policies.warehouse_id', $this->warehouseId)
            ->select('wms_item_reorder_policies.id', 'wms_item_reorder_policies.is_enabled', 'wms_item_reorder_policies.channel',
                'i.code as item_code', 'i.name as item_name', 'c.code as contractor_code', 'c.name as contractor_name',
                'sw.code as source_code', 'sw.name as source_name')->findOrFail($id);

        return $policy->toArray();
    }

    public function savePolicy(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        $this->assertEditorRoute();
        if (($this->form['channel'] ?? '') === 'FAX') {
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => 'FAX発注は参考計算のみです。設定の保存・自動発注ONはできません。']);
        }
        if ($this->recommendationPreferencesChanged || $this->recommendationSafetyDays !== $this->appliedRecommendationSafetyDays
            || ($this->recommendationContext !== null && $this->recommendationContext !== $this->currentRecommendationContext())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['operation' => '算定条件が変わりました。「おすすめ設定」で計算してから保存してください。']);
        }
        $this->validate(['form.is_enabled' => 'required|boolean'], trans('auto-replenishment.messages', [], 'ja'), trans('auto-replenishment.attributes', [], 'ja'));
        $enabled = (bool) $this->form['is_enabled'];
        $policy = $this->checked(fn () => DB::connection('sakemaru')->transaction(function () use ($clientId, $enabled) {
            app(ReplenishmentGuard::class)->control($clientId);
            $query = WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $this->warehouseId]);
            $p = $this->editingId ? $query->findOrFail($this->editingId) : $query->where('item_id', $this->form['item_id'] ?? 0)->first();
            if ($this->editingId) {
                abort_unless((int) $p->item_id === (int) ($this->form['item_id'] ?? 0), 422);
            }
            if ($enabled || ($p && (bool) $p->is_enabled !== $enabled)) {
                $this->authorizeOperation('auto-replenishment-settings', 'enable');
            }

            return app(PolicyService::class)->saveWithState($clientId, (int) $this->warehouseId,
                array_replace($this->form, ['pallet_unit_version' => (int) ($this->palletUnit['version'] ?? 0),
                    'shipping_profile_version' => (int) ($this->shippingProfile['version'] ?? 0)]),
                $enabled, (int) auth()->id(), $this->recommendation['basis'] ?? null);
        }, 3));
        $this->checked(fn () => $this->saveEventSetup($clientId, (int) $policy->item_id));
        unset($this->selectedVersions[(string) $policy->id]);
        $this->showEditor = false;
        Notification::make()->success()->title('商品設定を'.($enabled ? 'ON' : 'OFF').'で保存しました。')->send();
    }

    public function editWarehouse(): void
    {
        $this->authorizeOperation('auto-replenishment-settings', 'enable-warehouse');
        $this->resetValidation();
        $row = DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->where('warehouse_id', $this->warehouseId)->first();
        $defaults = ['execution_time' => '10:00', 'sunday_execution_time' => '22:00', 'weekdays' => [0, 1, 2, 3, 4, 5, 6]];
        $this->warehouseForm = array_intersect_key(json_decode($row?->replenishment_config ?? '{}', true), $defaults) + $defaults;
        $c = app(ReplenishmentGuard::class)->control(app(ReplenishmentGuard::class)->clientId(), false);
        $this->warehouseForm['version'] = (int) ($row->replenishment_version ?? 0);
        $this->warehouseForm['generation'] = (int) $c->generation;
        $this->warehouseForm['enabled'] = $row?->replenishment_config ? (bool) $row->is_auto_order_enabled : false;
        $this->showWarehouseEditor = true;
    }

    public function saveWarehouse(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'enable-warehouse');
        $data = $this->warehouseForm;
        $enabled = (bool) ($data['enabled'] ?? false);
        unset($data['enabled']);
        $this->checked(fn () => app(PolicyService::class)->saveWarehouse($clientId, (int) $this->warehouseId, $data, $enabled, (int) auth()->id()));
        $this->showWarehouseEditor = false;
    }

    public function setGlobalEnabled(bool $enabled): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'enable-global');
        DB::connection('sakemaru')->transaction(function () use ($clientId, $enabled) {
            $c = app(ReplenishmentGuard::class)->control($clientId);
            abort_unless($c->reset_state === 'COMPLETE', 422, '初期化が未完了です。');
            DB::connection('sakemaru')->table('wms_auto_order_controls')->where('id', $c->id)->update(['enabled' => $enabled, 'generation' => $c->generation + 1, 'updated_at' => now()]);
            app(ReplenishmentGuard::class)->event($clientId, 'GLOBAL_ENABLED_CHANGED', ['enabled' => $enabled], actorId: (int) auth()->id());
        }, 3);
    }

    public function preview(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-checklist', 'preview');
        $run = app(ExecutionService::class)->createRun($clientId, (int) $this->warehouseId, true, '');
        $this->runId = (int) $run->id;
        ProcessAutoReplenishmentRun::dispatch((int) $run->id)->afterCommit();
        Notification::make()->info()->title('試算を開始しました。発注データは生成しません。')->send();
    }

    public function showDetail(int $id): void
    {
        abort_unless(in_array(static::$screen, ['checklist', 'history']), 403);
        $this->detailQuery()->findOrFail($id);
        $this->detailId = $id;
    }

    private function detailQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return WmsAutoOrderRunLine::where(['client_id' => $this->clientId(), 'warehouse_id' => $this->warehouseId])
            ->when(static::$screen === 'checklist', fn ($q) => $q->where('run_id', $this->runId ?? 0), fn ($q) => $q->whereNotNull('generated_at'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('wms_auto_order_runs as dr')->whereColumn('dr.id', 'wms_auto_order_run_lines.run_id')->where('dr.is_preview', static::$screen === 'checklist'));
    }

    public function resume(int $id): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-history', 'resume');
        $line = WmsAutoOrderRunLine::where(['client_id' => $clientId, 'warehouse_id' => $this->warehouseId])->findOrFail($id);
        abort_unless(in_array($line->phase, ['INCOMING_CREATED', 'TRANSFER_PREPARED', 'FAILED_RETRYABLE']), 422);
        ProcessAutoReplenishmentRun::dispatch((int) $line->run_id, (int) $line->id)->afterCommit();
    }

    public function abortUnpublished(int $id): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-history', 'abort');
        WmsAutoOrderRunLine::where(['client_id' => $clientId, 'warehouse_id' => $this->warehouseId])->findOrFail($id);
        $this->checked(fn () => app(HistoryService::class)->abortUnpublished($clientId, $id, $this->abortReason, (int) auth()->id()));
        $this->detailId = null;
    }

    public function selectPolicy(int $id, int $version): void
    {
        $this->authorizeOperation('auto-replenishment-settings', 'edit');
        $visible = $this->getViewData()['rows']->getCollection();
        abort_unless($visible->contains('id', $id), 422);
        if (isset($this->selectedVersions[(string) $id])) {
            unset($this->selectedVersions[(string) $id]);
        } else {
            abort_if(count($this->selectedVersions) >= 100, 422);
            $this->selectedVersions[(string) $id] = $version;
        }
    }

    public function confirmSelectedState(bool $enabled): void
    {
        $this->openPolicyConfirmation($this->selectedVersions, $enabled, true);
    }

    public function clearSelection(): void
    {
        $this->authorizeOperation('auto-replenishment-settings', 'edit');
        $this->selectedVersions = [];
    }

    private function openPolicyConfirmation(array $versions, bool $enabled, bool $bulk): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'enable');
        $this->resetValidation();
        $policies = WmsItemReorderPolicy::leftJoin('items as i', 'i.id', '=', 'wms_item_reorder_policies.item_id')
            ->where('wms_item_reorder_policies.client_id', $clientId)->where('wms_item_reorder_policies.warehouse_id', $this->warehouseId)
            ->whereIn('wms_item_reorder_policies.id', array_keys($versions))->orderBy('wms_item_reorder_policies.id')
            ->get(['wms_item_reorder_policies.id', 'wms_item_reorder_policies.version', 'wms_item_reorder_policies.is_enabled', 'i.code as item_code', 'i.name as item_name']);
        $plan = $this->checked(fn () => app(\App\Services\AutoReplenishment\PolicyStateChangePlan::class)->build($versions, $policies->toArray(), $enabled));
        $state = $enabled ? 'ON' : 'OFF';
        $this->confirmation = $plan + ['action' => 'policies', 'bulk' => $bulk, 'warehouse_id' => $this->warehouseId,
            'title' => '商品別自動発注の変更確認', 'message' => '選択した商品の自動発注を'.$state.'に変更します。',
            'confirm_label' => $plan['change_count'].'件を'.$state.'にする'];
    }

    public function requestConfirmation(string $action, ?int $id = null): void
    {
        $this->resetValidation();
        $confirmation = match ($action) {
            'global-on', 'global-off' => ['title' => '全体の自動発注の変更確認',
                'message' => '全体の自動発注を'.($action === 'global-on' ? 'ON' : 'OFF').'にします。倉庫と商品それぞれのON/OFF設定は保持します。',
                'confirm_label' => '全体を'.($action === 'global-on' ? 'ON' : 'OFF').'にする'],
            'csv' => ['title' => 'CSV取込の確認', 'message' => count($this->csvPreview).'件の商品設定を取り込みます。保存後はすべて商品OFFになります。', 'confirm_label' => '確認内容で取り込む'],
            'resume' => ['title' => '未完了工程の再開確認', 'message' => '同じ実行IDの未完了工程を再開します。作成済みのデータは再作成しません。', 'confirm_label' => '未完了工程を再開する'],
            'abort' => ['title' => '未公開処理の中止確認', 'message' => 'JX未公開・未受入を確認し、内部予定を取り消します。自動発注履歴は残ります。', 'confirm_label' => '未公開処理を中止する'],
            default => abort(422),
        };
        $this->authorizeOperation(str_starts_with($action, 'global-') || $action === 'csv' ? 'auto-replenishment-settings' : 'auto-replenishment-history',
            match ($action) {
                'global-on', 'global-off' => 'enable-global', 'csv' => 'edit', 'resume' => 'resume', 'abort' => 'abort'
            });
        if (in_array($action, ['resume', 'abort'])) {
            $this->detailQuery()->findOrFail($id);
        }
        abort_if($action === 'csv' && $this->csvPreview === [], 422);
        $this->confirmation = $confirmation + ['action' => $action, 'id' => $id, 'warehouse_id' => $this->warehouseId,
            'generation' => str_starts_with($action, 'global-') ? (int) app(ReplenishmentGuard::class)->control($this->clientId(), false)->generation : null,
            'csv_hash' => $action === 'csv' ? hash('sha256', json_encode($this->csvPreview, JSON_THROW_ON_ERROR)) : null,
            'abort_reason' => $action === 'abort' ? $this->abortReason : null];
    }

    public function cancelConfirmation(): void
    {
        $this->confirmation = null;
        $this->resetValidation();
    }

    public function applyConfirmation(): void
    {
        $confirmation = $this->confirmation;
        abort_unless($confirmation && $confirmation['warehouse_id'] === $this->warehouseId, 422);
        $clientId = $this->clientId();
        if ($confirmation['action'] === 'policies') {
            $this->authorizeOperation('auto-replenishment-settings', 'enable');
            $changed = $this->checked(fn () => app(PolicyService::class)->setSelectedEnabled($clientId, (int) $this->warehouseId,
                $confirmation['versions'], $confirmation['enabled'], (int) auth()->id()));
            if ($confirmation['bulk']) {
                $this->selectedVersions = [];
            } else {
                foreach (array_keys($confirmation['versions']) as $id) {
                    unset($this->selectedVersions[(string) $id]);
                }
            }
            Notification::make()->success()->title($changed.'件を'.($confirmation['enabled'] ? 'ON' : 'OFF').'にしました。')->send();
        } elseif (str_starts_with($confirmation['action'], 'global-')) {
            $this->checked(function () use ($clientId, $confirmation) {
                DB::connection('sakemaru')->transaction(function () use ($clientId, $confirmation) {
                    $control = app(ReplenishmentGuard::class)->control($clientId);
                    throw_unless((int) $control->generation === $confirmation['generation'], \RuntimeException::class, '全体設定が変更されています。確認画面を閉じて、もう一度操作してください。');
                    $this->setGlobalEnabled($confirmation['action'] === 'global-on');
                }, 3);
            });
        } else {
            if ($confirmation['action'] === 'csv') {
                $this->checked(fn () => throw_unless(hash_equals($confirmation['csv_hash'], hash('sha256', json_encode($this->csvPreview, JSON_THROW_ON_ERROR))),
                    \RuntimeException::class, 'CSVの確認内容が変更されています。確認画面を閉じて、取り込む内容をもう一度確認してください。'));
            }
            match ($confirmation['action']) {
                'csv' => $this->applyCsv(),
                'resume' => $this->resume((int) $confirmation['id']),
                'abort' => $this->abortConfirmed($confirmation),
                default => abort(422),
            };
        }
        $this->confirmation = null;
    }

    private function abortConfirmed(array $confirmation): void
    {
        $this->abortReason = $confirmation['abort_reason'];
        $this->abortUnpublished((int) $confirmation['id']);
    }

    public function previewCsv(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        $this->resetValidation();
        $this->validate(['csvFile' => 'required|file|max:1024'], trans('auto-replenishment.messages', [], 'ja'), trans('auto-replenishment.attributes', [], 'ja'));
        try {
            $this->csvPreview = app(\App\Services\AutoReplenishment\PolicyCsvService::class)->preview($clientId, (int) $this->warehouseId, file_get_contents($this->csvFile->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['csvFile' => $e->getMessage()]);
        }
    }

    public function applyCsv(): void
    {
        $clientId = $this->authorizeOperation('auto-replenishment-settings', 'edit');
        $this->checked(fn () => app(\App\Services\AutoReplenishment\PolicyCsvService::class)->apply($clientId, (int) $this->warehouseId, $this->csvPreview, (int) auth()->id()));
        $this->csvPreview = [];
        $this->showCsv = false;
        $this->csvFile = null;
        Notification::make()->success()->title('CSVを取り込みました。全商品OFFで保存しました。')->send();
    }

    public function openRun(int $id): void
    {
        $clientId = $this->clientId();
        WmsAutoOrderRun::where(['client_id' => $clientId, 'warehouse_id' => $this->warehouseId, 'is_preview' => false])->findOrFail($id);
        $this->historyRunId = $id;
        $this->historyView = 'lines';
        $this->resetPage();
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(in_array(static::$screen, ['settings', 'checklist']), 403);
        $this->clientId();
        $rows = $this->getViewData()['rows']->getCollection();
        if (static::$screen === 'checklist') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['商品CD', '商品名', '方式', '現在庫', '未入荷残', '候補確保', '提案総バラ', '結果', '判定理由'], escape: '');
                foreach ($rows as $row) {
                    fputcsv($out, [$row->item_code, $row->item_name, $row->channel, $row->stock, $row->incoming, $row->claims, $row->quantity_pieces, $row->phase, $row->reason], escape: '');
                }
                fclose($out);
            }, 'auto-order-preview.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, \App\Services\AutoReplenishment\PolicyCsvService::HEADERS, escape: '');
            $db = DB::connection('sakemaru');
            $contractors = $db->table('contractors')->whereIn('id', $rows->pluck('contractor_id'))->pluck('code', 'id');
            $suppliers = $db->table('suppliers as s')->join('partners as p', 'p.id', '=', 's.partner_id')->whereIn('s.id', $rows->pluck('supplier_id'))->pluck('p.code', 's.id');
            $sources = $db->table('warehouses')->whereIn('id', $rows->pluck('source_warehouse_id')->filter())->pluck('code', 'id');
            $courses = $db->table('delivery_courses')->whereIn('id', $rows->pluck('delivery_course_id')->filter())->pluck('code', 'id');
            foreach ($rows as $row) {
                $contractor = $contractors[$row->contractor_id] ?? '';
                $supplier = $suppliers[$row->supplier_id] ?? '';
                $source = $sources[$row->source_warehouse_id] ?? '';
                $course = $courses[$row->delivery_course_id] ?? '';
                fputcsv($out, [$row->item_code, $row->channel, $contractor, $supplier, $source, $course, $row->reorder_point_pieces, $row->max_order_pieces, $row->min_order_pieces, $row->order_multiple_pieces], escape: '');
            }
            fclose($out);
        }, 'auto-order-settings.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function getViewData(): array
    {
        $clientId = $this->clientId();
        $db = DB::connection('sakemaru');
        $warehouses = $db->table('warehouses as w')->leftJoin('wms_warehouse_auto_order_settings as s', 's.warehouse_id', '=', 'w.id')
            ->where('w.client_id', $clientId)->where('w.is_active', true)->where('w.is_virtual', false)
            ->orderBy('w.code')->get(['w.id', 'w.code', 'w.name', DB::raw('CASE WHEN s.replenishment_config IS NOT NULL THEN s.is_auto_order_enabled ELSE 0 END AS is_auto_order_enabled')]);
        $warehouse = $warehouses->firstWhere('id', $this->warehouseId);
        $scopeWarehouseId = $warehouse?->id ?? 0;
        $screen = static::$screen;
        $control = $db->table('wms_auto_order_controls')->where('client_id', $clientId)->first();
        $run = null;
        if ($screen === 'settings') {
            $q = WmsItemReorderPolicy::query()->leftJoin('items as i', 'i.id', '=', 'wms_item_reorder_policies.item_id')
                ->leftJoin('contractors as rc', 'rc.id', '=', 'wms_item_reorder_policies.contractor_id')
                ->leftJoin('warehouses as sw', 'sw.id', '=', 'wms_item_reorder_policies.source_warehouse_id')
                ->where('wms_item_reorder_policies.client_id', $clientId)->where('wms_item_reorder_policies.warehouse_id', $scopeWarehouseId)
                ->select(array_map(fn ($column) => 'wms_item_reorder_policies.'.$column, ['id', 'item_id', 'contractor_id', 'supplier_id', 'source_warehouse_id', 'delivery_course_id', 'channel', 'reorder_point_pieces', 'max_order_pieces', 'min_order_pieces', 'order_multiple_pieces', 'is_configured', 'is_enabled', 'version', 'updated_at']))
                ->addSelect('i.code as item_code', 'i.name as item_name', 'rc.code as contractor_code', 'rc.name as contractor_name', 'sw.code as source_code', 'sw.name as source_name');
            if ($this->search !== '') {
                $term = mb_convert_kana($this->search, 'as');
                $q->where(fn ($q) => $q->where('i.code', 'like', $term.'%')->orWhere('i.name', 'like', '%'.$term.'%'));
            }
            app(\App\Services\AutoReplenishment\ItemSearchCodes::class)->apply($q, $clientId, $this->janSearch, 'wms_item_reorder_policies.item_id');
            if (in_array($this->status, ['ON', 'OFF'])) {
                $q->where('is_enabled', $this->status === 'ON');
            }
        } elseif ($screen === 'history' && $this->historyView === 'runs') {
            $q = WmsAutoOrderRun::where(['client_id' => $clientId, 'warehouse_id' => $scopeWarehouseId, 'is_preview' => false]);
        } else {
            if ($screen === 'checklist') {
                $run = WmsAutoOrderRun::where(['client_id' => $clientId, 'warehouse_id' => $scopeWarehouseId, 'is_preview' => true])->when($this->runId, fn ($q) => $q->where('id', $this->runId))->orderByDesc('id')->first();
                $this->runId = $run?->id;
            }
            $q = WmsAutoOrderRunLine::where(['client_id' => $clientId, 'warehouse_id' => $scopeWarehouseId, 'origin' => 'AUTOMATIC'])
                ->when($screen === 'checklist', fn ($q) => $q->where('run_id', $run?->id ?? 0), fn ($q) => $q->whereNotNull('generated_at')->when($this->historyRunId, fn ($q) => $q->where('run_id', $this->historyRunId)))
                ->select(['id', 'run_id', 'channel', 'phase', 'reason', 'quantity_pieces', 'generated_at', 'created_at'])
                ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(proposal_snapshot, '$.item_code')) item_code, JSON_UNQUOTE(JSON_EXTRACT(proposal_snapshot, '$.item_name')) item_name")
                ->selectRaw("JSON_EXTRACT(proposal_snapshot, '$.stock') stock, JSON_EXTRACT(proposal_snapshot, '$.incoming') incoming, JSON_UNQUOTE(JSON_EXTRACT(confirmation_snapshot, '$.arrival_date')) arrival_date, JSON_EXTRACT(proposal_snapshot, '$.claims') claims, JSON_UNQUOTE(JSON_EXTRACT(proposal_snapshot, '$.contractor_name')) contractor_name, JSON_UNQUOTE(JSON_EXTRACT(proposal_snapshot, '$.contractor_code')) contractor_code, JSON_UNQUOTE(JSON_EXTRACT(artifact_output_snapshot, '$.detail.b_delivery_date')) output_arrival, JSON_UNQUOTE(JSON_EXTRACT(artifact_output_snapshot, '$.transfer.delivered_date')) transfer_arrival, JSON_UNQUOTE(JSON_EXTRACT(artifact_output_snapshot, '$.transfer.picking_date')) picking_date");
            if ($this->search !== '') {
                $q->whereRaw("(JSON_UNQUOTE(JSON_EXTRACT(proposal_snapshot, '$.item_code')) LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(proposal_snapshot, '$.item_name')) LIKE ?)", [$this->search.'%', '%'.$this->search.'%']);
            }
            if ($this->status !== '') {
                $q->where('phase', $this->status);
            }
        }
        if (! ($screen === 'history' && $this->historyView === 'runs') && in_array($this->channel, ['EOS', 'LOGISTICS'])) {
            $q->where('channel', $this->channel);
        }
        $rows = $q->orderByDesc($screen === 'settings' ? 'wms_item_reorder_policies.id' : 'id')->simplePaginate(100);
        $phaseCounts = $run ? WmsAutoOrderRunLine::where('run_id', $run->id)->groupBy('phase')->selectRaw('phase, COUNT(*) total')->pluck('total', 'phase') : collect();
        $listLinks = $screen === 'history' && $this->historyView === 'lines'
            ? $db->table('wms_auto_order_artifact_links')->whereIn('line_id', $rows->getCollection()->pluck('id'))->get(['line_id', 'artifact_type', 'artifact_id', 'business_number'])->groupBy('line_id') : collect();
        $listTargets = [];
        foreach (['incoming' => 'wms_order_incoming_schedules', 'jx' => 'wms_order_jx_documents', 'stock_transfer' => 'stock_transfers'] as $type => $table) {
            $ids = $listLinks->flatten(1)->where('artifact_type', $type)->pluck('artifact_id');
            $listTargets[$type] = $ids->isNotEmpty() ? $db->table($table)->whereIn('id', $ids)->get($type === 'stock_transfer' ? ['id', 'is_active'] : ['id', 'status'])->keyBy('id') : collect();
        }
        $detail = $this->detailId ? $this->detailQuery()->find($this->detailId) : null;
        $links = $detail ? $db->table('wms_auto_order_artifact_links')->where('line_id', $detail->id)->get() : collect();
        // Only resolve targets for the open detail, never one query per list row.
        $targets = [];
        foreach (['incoming' => 'wms_order_incoming_schedules', 'jx' => 'wms_order_jx_documents', 'stock_transfer' => 'stock_transfers'] as $type => $table) {
            $targets[$type] = $db->table($table)->whereIn('id', $links->where('artifact_type', $type)->pluck('artifact_id'))->get()->keyBy('id');
        }

        $eligibility = app(\App\Services\AutoReplenishment\OrderRouteEligibility::class);
        $referenceIds = $this->showEditor ? $eligibility->referenceContractorIds($clientId) : [];
        $eligibleContractorIds = $this->showEditor ? $db->table('contractors')->where('client_id', $clientId)->where('is_active', true)
            ->whereIn('id', ($this->form['channel'] ?? '') === 'FAX' ? $referenceIds : $eligibility->contractorIds($this->form['channel'] ?? 'EOS'))->pluck('id')->all() : [];
        $itemEligibleIds = $this->showEditor ? array_unique(array_merge($referenceIds, $eligibility->contractorIds('EOS'), $eligibility->contractorIds('LOGISTICS'))) : [];
        $selectedItem = $this->showEditor ? $db->table('items')->where('client_id', $clientId)
            ->where('id', $this->form['item_id'] ?? 0)->first(['id', 'code', 'name', 'capacity_case', 'capacity_carton']) : null;
        $orderUnit = null;
        if ($selectedItem) {
            try {
                $orderUnit = app(\App\Services\AutoReplenishment\OrderUnitService::class)->forEditor($clientId, $selectedItem, $this->form['channel'] ?? 'EOS', source: (int) ($this->form['source_warehouse_id'] ?? 0));
                $orderUnit = app(\App\Services\AutoReplenishment\OrderUnitService::class)->select($orderUnit, $this->form['order_unit_mode'] ?? 'AUTO');
            } catch (\RuntimeException $e) {
                $orderUnit = array_replace($orderUnit ?? [], ['label' => '発注単位を要確認', 'reason' => $e->getMessage()]);
            }
        }
        $itemOptions = collect();
        $itemSearchTerm = mb_convert_kana(trim($this->itemSearch), 'as');
        $codeSearch = app(\App\Services\AutoReplenishment\ItemSearchCodes::class);
        $itemJanSearchTerm = implode(' ', $codeSearch->terms($this->itemJanSearch));
        if ($this->showEditor && $this->showItemPicker && ! $this->editingId && ($itemSearchTerm !== '' || $itemJanSearchTerm !== '')) {
            $query = $this->itemSelectionQuery($clientId, $itemEligibleIds);
            foreach (preg_split('/\s+/u', $itemSearchTerm, -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $query->where(fn ($q) => $q->where('code', 'like', $term.'%')->orWhere('name', 'like', '%'.$term.'%'));
            }
            $codeSearch->apply($query, $clientId, $this->itemJanSearch, 'items.id');
            $itemOptions = $query->orderBy('code')->limit(31)->get(['id', 'code', 'name', 'capacity_case', 'capacity_carton']);
        }
        $itemSearchHasMore = $itemOptions->count() > 30;
        $itemOptions = $itemOptions->take(30);
        $routeOptions = $this->showEditor ? $db->table('item_contractors as ic')->join('contractors as c', 'c.id', '=', 'ic.contractor_id')
            ->join('suppliers as s', 's.id', '=', 'ic.supplier_id')->join('partners as sp', 'sp.id', '=', 's.partner_id')
            ->where('ic.client_id', $clientId)->where('ic.warehouse_id', $this->warehouseId)->where('ic.item_id', $this->form['item_id'] ?? 0)
            ->where('c.is_active', true)->whereIn('ic.contractor_id', $eligibleContractorIds)
            ->get(['ic.contractor_id', 'ic.supplier_id', 'c.code', 'c.name', 'sp.code as supplier_code', 'sp.name as supplier_name']) : collect();
        $courseOptions = $this->showEditor ? $db->table('warehouse_stock_transfer_delivery_courses as p')->join('delivery_courses as dc', 'dc.id', '=', 'p.delivery_course_id')->where('p.to_warehouse_id', $this->warehouseId)
            ->where('p.from_warehouse_id', $this->form['source_warehouse_id'] ?? 0)->get(['p.delivery_course_id', 'p.picking_lead_days', 'dc.code', 'dc.name']) : collect();

        $selection = $screen === 'settings' && $this->selectedVersions
            ? WmsItemReorderPolicy::where(['client_id' => $clientId, 'warehouse_id' => $scopeWarehouseId])->whereIn('id', array_keys($this->selectedVersions))->get(['id', 'is_enabled']) : collect();
        $selectedOnCount = $selection->where('is_enabled', true)->count();
        $selectedOffCount = $selection->where('is_enabled', false)->count();
        $routeWarnings = $this->showEditor
            ? app(\App\Services\AutoReplenishment\MasterRouteWarnings::class)->forRoute($clientId, (int) $scopeWarehouseId, $this->form) : [];
        // Last weekly refresh: the products held or failed, with reasons, so the operator can review them on this screen.
        $weeklyRefresh = $screen === 'settings' && $warehouse ? app(\App\Services\AutoReplenishment\RecommendationRefreshService::class)->lastReport($clientId, (int) $scopeWarehouseId) : null;

        return compact('screen', 'warehouse', 'warehouses', 'control', 'rows', 'run', 'detail', 'links', 'targets', 'selectedItem', 'orderUnit', 'itemOptions', 'itemSearchTerm', 'itemJanSearchTerm', 'itemSearchHasMore', 'routeOptions', 'courseOptions', 'routeWarnings', 'phaseCounts', 'listLinks', 'listTargets', 'selectedOnCount', 'selectedOffCount', 'weeklyRefresh');
    }

    private function itemSelectionQuery(int $clientId, array $contractorIds): \Illuminate\Database\Query\Builder
    {
        return DB::connection('sakemaru')->table('items')->where(['client_id' => $clientId, 'is_active' => true, 'is_ended' => false])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('item_contractors as eligible_route')
                ->join('contractors as eligible_contractor', 'eligible_contractor.id', '=', 'eligible_route.contractor_id')
                ->whereColumn('eligible_route.item_id', 'items.id')->where('eligible_route.client_id', $clientId)
                ->where('eligible_contractor.client_id', $clientId)->where('eligible_contractor.is_active', true)
                ->where('eligible_route.warehouse_id', $this->warehouseId)
                ->whereIn('eligible_route.contractor_id', $contractorIds));
    }
}
