<?php

namespace App\Filament\Pages;

use App\Enums\EMenuCategory;
use App\Filament\Support\AdminPage;
use App\Models\WmsDemandEvent;
use App\Models\WmsDemandEventProfile;
use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\DemandEventCalendar;
use App\Services\AutoReplenishment\DemandEventLearner;
use App\Services\AutoReplenishment\DemandEventService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Sakemaru\Auth\Services\PermissionService;

/** Event master for the recommendation: yearly proposals, approval, learned factors. */
class AutoReplenishmentEvents extends AdminPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $slug = 'auto-order/events';

    protected static ?string $navigationLabel = 'イベント設定';

    protected static ?int $navigationSort = 4;

    protected static string $permissionResource = 'auto-replenishment-settings';

    protected string $view = 'filament.pages.auto-replenishment-events';

    #[Url]
    public int $year = 0;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    #[Locked]
    public ?int $previewId = null;

    /** 商品別の適用イベント: shown on demand; item_id (string key) => [type => bool]. */
    public bool $showItems = false;

    public array $itemEvents = [];

    public string $itemFilter = '';

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
        if ($this->year < 2000 || $this->year > 2100) {
            $this->year = (int) CarbonImmutable::now('Asia/Tokyo')->year;
        }
    }

    private function clientId(): int
    {
        abort_unless(static::canAccess(), 403);
        $clientId = app(ReplenishmentGuard::class)->clientId();
        abort_unless((int) auth()->user()->getAttribute('client_id') === $clientId, 403);

        return $clientId;
    }

    private function authorizeOperation(string $action): int
    {
        $clientId = $this->clientId();
        abort_unless(app(PermissionService::class)->check(auth()->user(), "wms.auto-replenishment-settings.{$action}"), 403);

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

    public function installed(): bool
    {
        return Schema::connection('sakemaru')->hasTable('wms_demand_events') && Schema::connection('sakemaru')->hasTable('wms_demand_event_profiles');
    }

    public function updatedYear(): void
    {
        $this->year = max(2000, min(2100, (int) $this->year));
        $this->previewId = null;
        $this->showForm = false;
    }

    public function propose(): void
    {
        $client = $this->authorizeOperation('edit');
        $inserted = $this->checked(fn () => app(DemandEventService::class)->proposeYear($client, $this->year, (int) auth()->id()));
        Notification::make()->success()->title("{$this->year}年のイベント素案を{$inserted}件追加しました。承認すると算定に反映されます。")->send();
    }

    /** Weekly preparation on demand: proposals, factors, backtest and automatic approval for the planning horizon. */
    public function prepare(): void
    {
        $client = $this->authorizeOperation('enable');
        $result = $this->checked(fn () => app(\App\Services\AutoReplenishment\DemandEventPipelineService::class)->run($client, CarbonImmutable::now('Asia/Tokyo')->toDateString(),
            (int) config('replenishment.demand_events.weekly_prepare.horizon_days', 63), (int) auth()->id(), true));
        $s = $result['summary'];
        Notification::make()->success()->title("今後のイベント {$s['upcoming']} 件を準備：自動承認 {$s['approved']}・要確認 {$s['needs_review']}・差し戻し {$s['reverted']}".($s['skipped'] ? "・対象外 {$s['skipped']}" : ''))->send();
    }

    public function create(): void
    {
        $this->authorizeOperation('edit');
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['type' => 'CUSTOM', 'name' => '', 'start_date' => '', 'end_date' => '', 'pre_days' => 2, 'post_days' => 1, 'note' => ''];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $client = $this->authorizeOperation('edit');
        $this->resetValidation();
        $event = WmsDemandEvent::where('client_id', $client)->findOrFail($id);
        $this->editingId = (int) $event->id;
        $this->form = ['type' => $event->type, 'name' => $event->name, 'start_date' => $event->start_date->toDateString(), 'end_date' => $event->end_date->toDateString(),
            'pre_days' => (int) $event->pre_days, 'post_days' => (int) $event->post_days, 'note' => (string) $event->note];
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        $client = $this->authorizeOperation('edit');
        $event = $this->checked(fn () => app(DemandEventService::class)->save($client, $this->form, (int) auth()->id(), $this->editingId));
        $this->showForm = false;
        $this->editingId = null;
        $this->year = (int) $event->year;
        Notification::make()->success()->title('イベントを保存しました（状態：'.WmsDemandEvent::STATUSES[$event->status].'）。')->send();
    }

    public function setStatus(int $id, string $status): void
    {
        // Approving, revoking and disabling all change what the recommendation uses: same permission as turning auto-order ON.
        $client = $this->authorizeOperation('enable');
        $event = $this->checked(fn () => app(DemandEventService::class)->setStatus($client, $id, $status, (int) auth()->id()));
        Notification::make()->success()->title($event->name.'を「'.WmsDemandEvent::STATUSES[$status].'」にしました。')->send();
    }

    public function toggleItems(): void
    {
        $client = $this->clientId();
        $this->showItems = ! $this->showItems;
        if ($this->showItems) {
            $this->itemEvents = [];
            foreach (app(\App\Services\AutoReplenishment\ItemDemandEventService::class)->matrix($client) as $itemId => $types) {
                $this->itemEvents[(string) $itemId] = $types;
            }
        }
    }

    /** Tick the products whose own learned factors suggest the event (nothing is unticked). */
    public function applySuggestions(): void
    {
        $client = $this->authorizeOperation('enable');
        $service = app(\App\Services\AutoReplenishment\ItemDemandEventService::class);
        $added = 0;
        foreach ($service->suggestions($client, array_map('intval', array_keys($this->itemEvents))) as $itemId => $types) {
            foreach ($types as $type => $info) {
                if ($info['suggest'] && empty($this->itemEvents[(string) $itemId][$type])) {
                    $this->itemEvents[(string) $itemId][$type] = true;
                    $added++;
                }
            }
        }
        Notification::make()->success()->title("候補 {$added} 件にチェックを入れました。内容を確認して「商品別の適用を保存」してください。")->send();
    }

    public function saveItemEvents(): void
    {
        // Applying an event to a product changes what the recommendation uses: same permission as approval.
        $client = $this->authorizeOperation('enable');
        $applied = [];
        foreach ($this->itemEvents as $itemId => $types) {
            $applied[(int) $itemId] = array_keys(array_filter(array_map(fn ($v) => (bool) $v, (array) $types)));
        }
        $counts = $this->checked(fn () => app(\App\Services\AutoReplenishment\ItemDemandEventService::class)->apply($client, $applied, (int) auth()->id()));
        Notification::make()->success()->title("商品別の適用を保存しました（適用 {$counts['enabled']}・解除 {$counts['disabled']}）。おすすめ設定には週次の再計算または画面の再計算で反映されます。")->send();
    }

    public function preview(int $id): void
    {
        $this->clientId();
        $this->previewId = $this->previewId === $id ? null : $id;
    }

    protected function getViewData(): array
    {
        $client = $this->clientId();
        if (! $this->installed()) {
            return ['installed' => false, 'events' => collect(), 'profileCounts' => [], 'preview' => null, 'trainedThrough' => null, 'proposals' => []];
        }
        $events = WmsDemandEvent::where(['client_id' => $client, 'year' => $this->year])->orderBy('start_date')->get();
        $counts = WmsDemandEventProfile::where('client_id', $client)->groupBy('profile_key', 'scope_type')
            ->selectRaw('profile_key, scope_type, COUNT(*) n, MAX(trained_through) trained_through')->get();
        $profileCounts = [];
        $trainedThrough = null;
        foreach ($counts as $row) {
            $profileCounts[$row->profile_key][$row->scope_type] = (int) $row->n;
            $trainedThrough = max($trainedThrough ?? '', (string) $row->trained_through);
        }
        $calendar = app(DemandEventCalendar::class);
        $proposals = $calendar->standardEvents($this->year, app(\App\Services\AutoReplenishment\DemandEventHistoryReader::class)->holidays($this->year));
        $existing = $events->map(fn ($e) => $e->type.'|'.$e->start_date->toDateString())->flip();
        $missing = array_values(array_filter($proposals, fn ($p) => ! isset($existing[$p['type'].'|'.$p['start']])));

        return ['installed' => true, 'events' => $events, 'profileCounts' => $profileCounts, 'trainedThrough' => $trainedThrough ?: null,
            'proposals' => $missing, 'preview' => $this->previewId ? $this->previewRows($client, $events->firstWhere('id', $this->previewId)) : null,
            'modelVersion' => DemandEventLearner::MODEL_VERSION, 'itemRows' => $this->showItems ? $this->itemRows($client) : [],
            'appliedCounts' => $this->appliedCounts($client)];
    }

    /** Applied products per standard event type. */
    private function appliedCounts(int $client): array
    {
        $counts = array_fill_keys(DemandEventCalendar::STANDARD_TYPES, 0);
        foreach (\App\Models\WmsItemDemandEvent::where(['client_id' => $client, 'is_enabled' => true])->groupBy('event_type')->selectRaw('event_type, COUNT(*) n')->get() as $row) {
            if (isset($counts[$row->event_type])) {
                $counts[$row->event_type] = (int) $row->n;
            }
        }

        return $counts;
    }

    /** Products with an auto-order policy, their applicability checkboxes and the learned-factor suggestions. */
    private function itemRows(int $client): array
    {
        $ids = array_map('intval', array_keys($this->itemEvents));
        if (! $ids) {
            return [];
        }
        $items = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', $ids)->get(['id', 'code', 'name', 'item_category3_id'])->keyBy('id');
        $categories = DB::connection('sakemaru')->table('item_categories')->whereIn('id', $items->pluck('item_category3_id')->filter()->unique()->all() ?: [-1])->pluck('name', 'id');
        $suggestions = app(\App\Services\AutoReplenishment\ItemDemandEventService::class)->suggestions($client, $ids);
        $filter = mb_strtolower(trim($this->itemFilter));
        $rows = [];
        foreach ($ids as $itemId) {
            $item = $items[$itemId] ?? null;
            $code = (string) ($item->code ?? $itemId);
            $name = (string) ($item->name ?? '（商品情報なし）');
            if ($filter !== '' && ! str_contains(mb_strtolower($code.' '.$name), $filter)) {
                continue;
            }
            $rows[] = ['item_id' => $itemId, 'code' => $code, 'name' => $name, 'category' => $categories[$item->item_category3_id ?? 0] ?? '',
                'suggestions' => $suggestions[$itemId] ?? []];
        }
        usort($rows, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /** Learned factors of the policies' products for one event: peak position and block mean. */
    private function previewRows(int $client, ?WmsDemandEvent $event): ?array
    {
        if (! $event) {
            return null;
        }
        $keys = [$event->name, $event->type];
        $items = WmsItemReorderPolicy::where('client_id', $client)->distinct()->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        $profiles = WmsDemandEventProfile::where('client_id', $client)->whereIn('profile_key', $keys)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('scope_type', 'ITEM')->whereIn('scope_id', $items ?: [-1]))->orWhere('scope_type', 'CATEGORY'))
            ->get();
        $itemRows = DB::connection('sakemaru')->table('items')->where('client_id', $client)->whereIn('id', $items ?: [-1])->get(['id', 'code', 'name', 'item_category3_id'])->keyBy('id');
        $categories = DB::connection('sakemaru')->table('item_categories')->whereIn('id', $itemRows->pluck('item_category3_id')->filter()->unique()->all() ?: [-1])->pluck('name', 'id');
        $byScope = [];
        foreach ($profiles as $profile) {
            $byScope[$profile->scope_type][(int) $profile->scope_id] ??= $profile; // name-specific key sorts first in $keys
        }
        $applied = \App\Models\WmsItemDemandEvent::where(['client_id' => $client, 'event_type' => $event->type, 'is_enabled' => true])->whereIn('item_id', $items ?: [-1])->pluck('item_id')->flip();
        // Period-average factors (Σadjusted forecast ÷ Σweekday forecast per segment) from the latest saved recommendation of each product.
        $segments = [];
        $eventStart = $event->start_date->toDateString();
        WmsItemReorderPolicy::where('client_id', $client)->whereIn('item_id', $items ?: [-1])->whereNotNull('calculation_basis')->select(['item_id', 'warehouse_id', 'calculation_basis'])
            ->lazyById(100)->each(function ($policy) use (&$segments, $event, $eventStart) {
                foreach ($policy->calculation_basis['event_adjustment']['events'] ?? [] as $row) {
                    if (($row['name'] ?? '') === $event->name && ($row['start'] ?? '') === $eventStart && ! empty($row['segments'])) {
                        $segments[(int) $policy->item_id] ??= ['warehouse_id' => (int) $policy->warehouse_id, 'segments' => $row['segments']];
                    }
                }
            });
        $rows = [];
        foreach ($items as $itemId) {
            $own = $byScope['ITEM'][$itemId] ?? null;
            $category = isset($itemRows[$itemId]) ? ($byScope['CATEGORY'][(int) $itemRows[$itemId]->item_category3_id] ?? null) : null;
            $source = $own ?: $category;
            $rows[] = ['item_id' => $itemId, 'code' => $itemRows[$itemId]->code ?? '', 'name' => $itemRows[$itemId]->name ?? '（商品情報なし）',
                'category' => $categories[$itemRows[$itemId]->item_category3_id ?? 0] ?? '', 'source' => $own ? '自社' : ($category ? 'カテゴリ（参考）' : 'なし'),
                'applied' => isset($applied[$itemId]), 'segments' => $segments[$itemId]['segments'] ?? null,
                'summary' => $source ? $this->summarize($source->positions, $event->type) : null, 'pieces' => $own ? (float) $own->pieces : null];
        }
        usort($rows, fn ($a, $b) => [$b['applied'], $b['summary']['peak'] ?? 0] <=> [$a['applied'], $a['summary']['peak'] ?? 0]);

        return ['event' => $event, 'rows' => $rows, 'categories' => count($byScope['CATEGORY'] ?? []), 'items' => count($byScope['ITEM'] ?? [])];
    }

    private function summarize(array $positions, string $type): array
    {
        $block = array_filter($positions, fn ($p, $pos) => ! str_starts_with($pos, 'pre') && ! str_starts_with($pos, 'post'), ARRAY_FILTER_USE_BOTH);
        $peakPos = null;
        $peak = 0.0;
        $sum = 0.0;
        $max = 0.0;
        foreach ($block as $pos => $p) {
            $value = max((float) ($p['factor'] ?? 0), (float) ($p['factor_weekday'] ?? 0));
            $sum += $value;
            if ($value > $peak) {
                $peak = $value;
                $peakPos = $pos;
            }
            $max = max($max, (int) ($p['years'] ?? 0));
        }
        $label = fn ($pos) => match (true) {
            $pos === null => '—', $pos === 'd1' => '初日', $pos === 'mid' => '中日', $pos === 'last' => '最終日',
            str_contains($pos, '-') => (int) substr($pos, 0, 2).'/'.(int) substr($pos, 3), default => $pos,
        };

        return ['peak' => round($peak, 2), 'peak_label' => $label($peakPos), 'mean' => $block ? round($sum / count($block), 2) : null, 'years' => $max, 'positions' => count($block)];
    }
}
