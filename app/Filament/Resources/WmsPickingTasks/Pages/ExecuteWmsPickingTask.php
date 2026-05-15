<?php

namespace App\Filament\Resources\WmsPickingTasks\Pages;

use App\Filament\Resources\WmsPickingTasks\WmsPickingTaskResource;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use App\Services\Shortage\PickingShortageDetector;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\DB;
use Sakemaru\Auth\Services\PermissionService;

class ExecuteWmsPickingTask extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = WmsPickingTaskResource::class;

    protected string $view = 'filament.resources.wms-picking-tasks.pages.execute-wms-picking-task';

    public WmsPickingTask $record;

    public array $items = [];

    public function mount(WmsPickingTask $record): void
    {
        // ステータスがPICKING_READY, PENDING, SHORTAGEの場合、PICKINGに変更
        if (in_array($record->status, ['PICKING_READY', 'PENDING', 'SHORTAGE'])) {
            $record->update([
                'status' => 'PICKING',
                'started_at' => $record->started_at ?? now(),
            ]);
        }

        $record->load([
            'pickingItemResults' => function ($query) {
                $query->with(['item', 'location', 'trade.partner', 'trade.earning.buyer.current_detail.salesman'])
                    ->orderBy('walking_order', 'asc')
                    ->orderBy('item_id', 'asc');
            },
            'wave',
            'floor',
            'pickingArea',
            'warehouse',
            'picker',
        ]);
        $this->record = $record;
        // 商品データを配列に変換
        $this->items = $this->record->pickingItemResults->map(function ($item) {
            $trade = $item->trade;

            return [
                'id' => $item->id,
                'serial_id' => $trade->serial_id ?? 'N/A',
                'client_code' => $trade->partner->code ?? '-',
                'client_name' => $trade->partner->name ?? '-',
                'sales_rep_name' => $trade->earning?->buyer?->current_detail?->salesman?->name ?? '-',
                'item_code' => $item->item->code ?? '-',
                'item_name' => $item->item->name ?? "商品{$item->item_id}",
                'location' => $item->location_display ?? '-',
                'ordered_qty' => (int) $item->ordered_qty,
                'ordered_qty_type_display' => $item->ordered_qty_type_display,
                'planned_qty' => (int) $item->planned_qty,
                'planned_qty_type_display' => $item->planned_qty_type_display,
                'picked_qty' => (int) $item->picked_qty,
                'picked_qty_type_display' => $item->picked_qty_type_display,
                'shortage_qty' => (int) $item->shortage_qty,
                'status' => $item->status,
                'picked_at' => $item->picked_at?->format('H:i:s'),
            ];
        })->toArray();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('force_ship')
                ->label('強制出荷（管理者）')
                ->color('warning')
                ->icon('heroicon-o-truck')
                ->requiresConfirmation()
                ->modalHeading('強制出荷確認')
                ->modalDescription('すべての商品のピッキング数を予定数に自動設定し、出荷可能状態にします。テスト用・緊急用の機能です。')
                ->action(function () {
                    $this->forceShipTask();
                })
                ->visible(fn () => $this->record->status !== 'COMPLETED'),

            Action::make('complete')
                ->label('保存して完了')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('内容を保存しますか？')
                ->modalDescription('入力中のピック数量を保存し、タスクを完了します。')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('保存して完了')->color('danger'))
                ->modalCancelActionLabel('完了せず閉じる')
                ->action(function () {
                    $this->completeTask();
                })
                ->visible(fn () => $this->record->status !== 'COMPLETED'),

            Action::make('back')
                ->label('保存せず戻る')
                ->icon('heroicon-o-arrow-left')
                ->url($this->getBackUrl())
                ->color('gray'),
        ];
    }

    public function updateItem($itemId, $field, $value): void
    {
        DB::connection('sakemaru')->transaction(function () use ($itemId, $field, $value) {
            $item = $this->record->pickingItemResults()->find($itemId);

            if (! $item) {
                Notification::make()
                    ->title('エラー')
                    ->body('商品が見つかりません')
                    ->danger()
                    ->send();

                return;
            }

            // ピック数量更新（picked_qtyのみ受け付ける）
            if ($field === 'picked_qty') {
                // 整数に変換
                $pickedQty = (int) $value;
                $originalValue = $pickedQty;

                // 受注数を超える場合は受注数に補正
                $maxQty = max($item->ordered_qty, $item->planned_qty);
                if (! $this->allowsOverPickedQuantity() && $pickedQty > $maxQty) {
                    $pickedQty = $maxQty;
                    Notification::make()
                        ->title('数量を補正しました')
                        ->body("入力値（{$originalValue}）が受注数を超えているため、受注数（{$maxQty}）に補正しました")
                        ->warning()
                        ->send();
                }

                // 0未満の場合は0に補正
                if ($pickedQty < 0) {
                    $pickedQty = 0;
                    Notification::make()
                        ->title('数量を補正しました')
                        ->body('負の値は入力できません。0に補正しました')
                        ->warning()
                        ->send();
                }

                $shortageQty = max(0, $item->planned_qty - $pickedQty);

                $item->update([
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'status' => $shortageQty > 0 ? 'SHORTAGE' : 'COMPLETED',
                    'picked_at' => now(),
                ]);

                $this->syncShortageAfterPickingCorrection($item->fresh());
            }

        });

        $this->skipRender();
    }

    public function forceShipTask(): void
    {
        DB::connection('sakemaru')->transaction(function () {
            $items = $this->record->pickingItemResults;
            $updatedCount = 0;
            $shortagesCreated = 0;

            $shortageDetector = app(PickingShortageDetector::class);

            foreach ($items as $item) {
                $hasAllocationShortage = $item->ordered_qty > $item->planned_qty;

                $item->update([
                    'picked_qty' => $item->planned_qty,
                    'shortage_qty' => 0,
                    'status' => $hasAllocationShortage ? 'SHORTAGE' : 'COMPLETED',
                    'picked_at' => now(),
                ]);
                $updatedCount++;

                if ($hasAllocationShortage) {
                    $item->refresh();
                    $shortage = $shortageDetector->detectAndRecord(
                        pickResult: $item,
                        parentShortageId: null
                    );
                    if ($shortage) {
                        $shortagesCreated++;
                    }
                }
            }

            $hasShortage = $shortagesCreated > 0;
            $taskStatus = $hasShortage ? 'SHORTAGE' : 'COMPLETED';

            $this->record->update([
                'status' => $taskStatus,
                'completed_at' => now(),
            ]);

            $earningIds = $this->record->pickingItemResults()
                ->distinct('earning_id')
                ->whereNotNull('earning_id')
                ->pluck('earning_id')
                ->toArray();

            if (! empty($earningIds)) {
                DB::connection('sakemaru')
                    ->table('earnings')
                    ->whereIn('id', $earningIds)
                    ->update([
                        'picking_status' => 'COMPLETED',
                        'updated_at' => now(),
                    ]);
            }

            $message = "{$updatedCount}件の商品を自動完了し、出荷可能状態にしました";
            if ($shortagesCreated > 0) {
                $message .= "（引当欠品{$shortagesCreated}件を記録しました）";
            }

            Notification::make()
                ->title('強制出荷しました')
                ->body($message)
                ->success()
                ->send();

            $this->redirect($this->getBackUrl());
        });
    }

    public function completeTask(): void
    {
        DB::connection('sakemaru')->transaction(function () {
            $this->savePickedQuantities();

            // planned_qty = 0 のアイテムを自動完了（引当なし）
            $this->record->pickingItemResults()
                ->where('planned_qty', 0)
                ->whereIn('status', ['PENDING', 'PICKING'])
                ->update(['status' => 'COMPLETED', 'updated_at' => now()]);

            // 未ピッキング商品チェック（planned_qty > 0 かつ picked_qty = 0）
            // API経由でpicked_qty > 0 のアイテムはステータスがPICKINGでも完了可能
            $incompleteItems = $this->record->pickingItemResults()
                ->where('planned_qty', '>', 0)
                ->where('picked_qty', 0)
                ->whereIn('status', ['PENDING', 'PICKING'])
                ->count();

            if ($incompleteItems > 0) {
                Notification::make()
                    ->title('完了できません')
                    ->body("未完了の商品が{$incompleteItems}件あります（ピッキング数が未入力）")
                    ->warning()
                    ->send();

                return;
            }

            // PICKING状態でpicked_qty > 0のアイテムを最終ステータスに更新
            $pickingItems = $this->record->pickingItemResults()
                ->whereIn('status', ['PICKING'])
                ->get();

            foreach ($pickingItems as $item) {
                $itemStatus = $item->shortage_qty > 0 ? 'SHORTAGE' : 'COMPLETED';
                $item->update([
                    'status' => $itemStatus,
                    'picked_at' => $item->picked_at ?? now(),
                    'updated_at' => now(),
                ]);
            }

            // 欠品がある商品の欠品データを生成
            // ピッキング欠品(planned > picked)と引当欠品(ordered > planned)の両方を検出
            $shortageItems = $this->record->pickingItemResults()
                ->where(function ($q) {
                    $q->where('status', 'SHORTAGE')
                        ->orWhereColumn('ordered_qty', '>', 'planned_qty');
                })
                ->get();

            $shortageDetector = app(PickingShortageDetector::class);
            $shortagesCreated = 0;

            foreach ($shortageItems as $item) {
                // 欠品検出・記録
                $shortage = $shortageDetector->detectAndRecord(
                    pickResult: $item,
                    parentShortageId: null // 通常ピッキングでは親欠品なし
                );

                if ($shortage) {
                    $shortagesCreated++;
                }
            }

            // 未対応の欠品があるかどうかでステータスを判定
            // has_physical_shortage（ピッキング時欠品）は常に未対応
            // has_soft_shortage（引当時欠品）は対応済みの場合は除外
            $hasPhysicalShortage = $this->record->pickingItemResults()
                ->where('has_physical_shortage', true)
                ->exists();

            $hasUnresolvedSoftShortage = false;
            if (! $hasPhysicalShortage) {
                // 引当時欠品のうち、wms_shortagesで未対応（status=BEFORE）のものがあるか確認
                $softShortageItems = $this->record->pickingItemResults()
                    ->where('has_soft_shortage', true)
                    ->get();

                foreach ($softShortageItems as $item) {
                    $unresolvedShortage = WmsShortage::where('wave_id', $this->record->wave_id)
                        ->where('warehouse_id', $this->record->warehouse_id)
                        ->where('item_id', $item->item_id)
                        ->where('trade_item_id', $item->trade_item_id)
                        ->where('status', WmsShortage::STATUS_BEFORE)
                        ->exists();

                    if ($unresolvedShortage) {
                        $hasUnresolvedSoftShortage = true;
                        break;
                    }
                }
            }

            $taskStatus = ($hasPhysicalShortage || $hasUnresolvedSoftShortage) ? 'SHORTAGE' : 'COMPLETED';

            // タスクを完了または欠品状態に
            $this->record->update([
                'status' => $taskStatus,
                'completed_at' => now(),
            ]);

            // このタスクに関連する全ての伝票のピッキングステータスを更新
            $earningIds = $this->record->pickingItemResults()
                ->distinct('earning_id')
                ->whereNotNull('earning_id')
                ->pluck('earning_id')
                ->toArray();

            if (! empty($earningIds)) {
                DB::connection('sakemaru')
                    ->table('earnings')
                    ->whereIn('id', $earningIds)
                    ->update([
                        'picking_status' => 'COMPLETED',
                        'updated_at' => now(),
                    ]);
            }

            $quantityUpdateQueueCount = $this->createOverPickedQuantityUpdateQueues();

            $message = 'タスクが完了しました';
            if ($shortagesCreated > 0) {
                $message .= "（欠品{$shortagesCreated}件を記録しました）";
            }
            if ($quantityUpdateQueueCount > 0) {
                $message .= "（伝票数量更新{$quantityUpdateQueueCount}件を登録しました）";
            }

            Notification::make()
                ->title('ピッキング完了')
                ->body($message)
                ->success()
                ->send();

            $this->redirect($this->getBackUrl());
        });
    }

    private function savePickedQuantities(): void
    {
        $inputById = collect($this->items)
            ->mapWithKeys(fn (array $item) => [(int) $item['id'] => $item])
            ->all();

        $this->record->pickingItemResults()
            ->whereIn('id', array_keys($inputById))
            ->get()
            ->each(function ($item) use ($inputById) {
                $input = $inputById[(int) $item->id] ?? null;

                if ($input === null) {
                    return;
                }

                $pickedQty = (int) ($input['picked_qty'] ?? 0);
                $pickedQty = max($pickedQty, 0);
                if (! $this->allowsOverPickedQuantity()) {
                    $maxQty = max((int) $item->ordered_qty, (int) $item->planned_qty);
                    $pickedQty = min($pickedQty, $maxQty);
                }
                $shortageQty = max(0, (int) $item->planned_qty - $pickedQty);

                $item->update([
                    'picked_qty' => $pickedQty,
                    'shortage_qty' => $shortageQty,
                    'status' => $shortageQty > 0 ? 'SHORTAGE' : 'COMPLETED',
                    'picked_at' => $item->picked_at ?? now(),
                    'updated_at' => now(),
                ]);

                $this->syncShortageAfterPickingCorrection($item->fresh());
            });
    }

    private function syncShortageAfterPickingCorrection($item): void
    {
        $totalShortageQty = max(0, (int) $item->ordered_qty - (int) $item->picked_qty);
        $allocationShortageQty = max(0, (int) $item->ordered_qty - (int) $item->planned_qty);
        $pickingShortageQty = max(0, (int) $item->planned_qty - (int) $item->picked_qty);

        $shortage = WmsShortage::query()
            ->where(function ($query) use ($item) {
                $query->where('source_pick_result_id', $item->id)
                    ->orWhere(function ($subQuery) use ($item) {
                        $subQuery->where('wave_id', $this->record->wave_id)
                            ->where('warehouse_id', $this->record->warehouse_id)
                            ->where('item_id', $item->item_id)
                            ->where('trade_item_id', $item->trade_item_id);
                    });
            })
            ->lockForUpdate()
            ->first();

        if (! $shortage) {
            return;
        }

        if ($shortage->is_confirmed || $shortage->is_synced) {
            throw new \RuntimeException("欠品ID {$shortage->id} は承認済みまたは同期済みのため、ピッキング修正では戻せません。");
        }

        $allocationExists = DB::connection('sakemaru')
            ->table('wms_shortage_allocations')
            ->where('shortage_id', $shortage->id)
            ->exists();

        if ($allocationExists) {
            throw new \RuntimeException("欠品ID {$shortage->id} は横持ち出荷指示があるため、先に欠品対応を取り消してください。");
        }

        if ($totalShortageQty <= 0) {
            $shortage->delete();

            return;
        }

        $shortage->update([
            'picked_qty' => (int) $item->picked_qty,
            'shortage_qty' => $totalShortageQty,
            'allocation_shortage_qty' => $allocationShortageQty,
            'picking_shortage_qty' => $pickingShortageQty,
            'status' => WmsShortage::STATUS_BEFORE,
            'updated_at' => now(),
        ]);
    }

    protected function allowsOverPickedQuantity(): bool
    {
        return false;
    }

    public function canOverPick(): bool
    {
        return $this->allowsOverPickedQuantity();
    }

    protected function getBackUrl(): string
    {
        return WmsPickingTaskResource::getUrl('index');
    }

    protected function createOverPickedQuantityUpdateQueues(): int
    {
        if (! $this->allowsOverPickedQuantity()) {
            return 0;
        }

        $queueService = app(QuantityUpdateQueueService::class);
        $created = 0;

        $this->record->pickingItemResults()
            ->whereColumn('picked_qty', '>', 'ordered_qty')
            ->get()
            ->each(function ($item) use ($queueService, &$created) {
                if ($queueService->createQueueForPickingQuantityCorrection($item)) {
                    $created++;
                }
            });

        return $created;
    }

    public function getTitle(): string
    {
        $waveCode = $this->record->wave->wave_code ?? 'Wave';
        $taskId = $this->record->id;
        $floorName = $this->record->floor->name ?? 'フロア未設定';

        return "ピッキング実行: {$waveCode} - タスク #{$taskId} ({$floorName})";
    }

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(PermissionService::class)->check($user, 'wms.execute-wms-picking-task.execute');
    }
}
