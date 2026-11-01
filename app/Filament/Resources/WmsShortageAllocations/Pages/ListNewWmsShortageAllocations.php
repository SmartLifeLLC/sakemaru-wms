<?php

namespace App\Filament\Resources\WmsShortageAllocations\Pages;

use App\Enums\QuantityType;
use App\Filament\Resources\WmsShortageAllocations\WmsShortageAllocationResource as Resource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentLifecycleService;
use App\Services\Shortage\ProxyShipmentPrintRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Sakemaru\Auth\Services\PermissionService;

class ListNewWmsShortageAllocations extends ListRecords
{
    protected static string $resource = Resource::class;

    protected static ?string $title = '新横持ち出荷依頼';

    protected string $view = 'filament.pages.new-proxy-shipments';

    public function updatedPaginators($page, $pageName): void
    {
        $this->deselectAllTableRecords();
    }

    public function updatedTableFilters(): void
    {
        if (($this->tableFilters['status']['value'] ?? null) === 'CANCELLED') {
            $this->tableFilters['cancellation']['value'] = 'only';
        }
        parent::updatedTableFilters();
    }

    public function getTabs(): array
    {
        $tabs = ['all' => \Filament\Schemas\Components\Tabs\Tab::make('すべて')];
        foreach (Warehouse::where('client_id', config('app.client_id'))->where('is_virtual', false)->orderBy('code')->get() as $warehouse) {
            $tabs['wh_'.$warehouse->id] = \Filament\Schemas\Components\Tabs\Tab::make($warehouse->name)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('target_warehouse_id', $warehouse->id));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $key = 'wh_'.auth()->user()?->default_warehouse_id;

        return array_key_exists($key, $this->getCachedTabs()) ? $key : 'all';
    }

    public function updatedActiveTab(): void
    {
        $this->deselectAllTableRecords();
        parent::updatedActiveTab();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    private function printable(WmsShortageAllocation $record): bool
    {
        $queue = $record->createQueue;
        $transfer = $queue?->stockTransfer;

        return $record->is_finished && in_array($record->status, ['FULFILLED', 'SHORTAGE'], true)
            && $record->picked_qty > 0 && $queue?->status === 'FINISHED' && $queue->is_success
            && (int) $queue->proxy_create_count === 1
            && (int) $queue->client_id === (int) config('app.client_id')
            && $transfer?->is_active && $transfer->trade?->is_active && $transfer->trade?->is_latest
            && (int) $transfer->client_id === (int) config('app.client_id')
            && (int) $transfer->trade->client_id === (int) config('app.client_id')
            && (! $record->stock_transfer_queue_id || (int) $record->stock_transfer_queue_id === (int) $queue->id);
    }

    public function prepareTransferPrint(string $operationKey): ?string
    {
        abort_unless(Resource::canViewAny(), 403);
        foreach (['sakemaru.stock-transfers.view', 'sakemaru.stock-transfers.print'] as $permission) {
            abort_unless(app(PermissionService::class)->check(auth()->user(), $permission), 403);
        }
        // Selection is explicitly limited to the visible page, including the active tab and filters.
        $visible = collect($this->getTableRecords()->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_map('intval', $this->selectedTableRecords)));
        if ($this->isTrackingDeselectedTableRecords || array_diff($ids, $visible)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['allocation_ids' => '現在のページから印刷対象を選び直してください。']);
        }
        try {
            return app(ProxyShipmentPrintRequestService::class)->create($ids, (int) config('app.client_id'), auth()->id(), $operationKey);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            Notification::make()->title('印刷を開始できません')->body(collect($exception->errors())->flatten()->first())->danger()->send();

            return null;
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Resource::getEloquentQuery()->whereHas('shortage.trade', fn (Builder $query) => $query->where('client_id', config('app.client_id')))
                ->with(['sourceWarehouse', 'createQueue.stockTransfer.trade']))
            ->striped()->extraAttributes(['class' => 'sticky-actions'])->recordUrl(null)
            ->defaultSort('id', 'desc')->defaultPaginationPageOption(100)
            ->paginationPageOptions([100, 500, 1000])
            ->selectable()->selectCurrentPageOnly()->deselectAllRecordsWhenFiltered()
            ->checkIfRecordIsSelectableUsing(fn (WmsShortageAllocation $record) => $this->printable($record))
            ->columns([
                TextColumn::make('id')->label('依頼ID')->sortable(),
                TextColumn::make('shipment_date')->label('出荷日')->date('Y/m/d')->sortable(),
                TextColumn::make('targetWarehouse.code')->label('出荷倉庫CD'),
                TextColumn::make('targetWarehouse.name')->label('横持ち出荷倉庫'),
                TextColumn::make('sourceWarehouse.code')->label('元倉庫CD'),
                TextColumn::make('sourceWarehouse.name')->label('元倉庫'),
                TextColumn::make('shortage.item.code')->label('商品CD')->searchable(),
                TextColumn::make('shortage.item.name')->label('商品名')->grow()->searchable(),
                TextColumn::make('shortage.item.volume')->label('容量'),
                TextColumn::make('shortage.item.capacity_case')->label('入数'),
                TextColumn::make('shortage.trade.partner.name')->label('得意先'),
                TextColumn::make('deliveryCourse.name')->label('配送コース'),
                TextColumn::make('assign_qty_type')->label('単位')->formatStateUsing(fn ($state) => QuantityType::tryFrom($state)?->name() ?? $state),
                TextColumn::make('assign_qty')->label('予定数'),
                TextInputColumn::make('picked_qty')->label('ピック数')->type('number')
                    ->rules(['required', 'integer', 'min:0'])->validationAttribute('ピック数')
                    ->disabled(fn (WmsShortageAllocation $record) => ! Resource::canEdit($record) || ! in_array($record->status, ['RESERVED', 'PICKING']))
                    ->updateStateUsing(function (WmsShortageAllocation $record, $state) {
                        abort_unless(Resource::canEdit($record), 403);

                        return app(ProxyShipmentLifecycleService::class)->locked($record->id, function ($locked) use ($state) {
                            $locked->assertWorkable();
                            $locked->update(['picked_qty' => (int) $state]);

                            return $locked->picked_qty;
                        });
                    }),
                TextColumn::make('remaining_qty')->label('欠品数')->state(fn (WmsShortageAllocation $record) => $record->isCancelled() ? '対象外' : $record->remaining_qty),
                TextColumn::make('status')->label('作業状態')->badge()->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state),
                TextColumn::make('transfer_status')->label('倉庫移動伝票')
                    ->state(function (WmsShortageAllocation $record) {
                        if ($this->printable($record)) {
                            return '移動伝票 '.$record->createQueue->stockTransfer->trade->serial_id;
                        }
                        if ($record->isCancelled()) {
                            return '取消（無効）';
                        }
                        if ($record->transfer_not_required_reason) {
                            return '移動不要';
                        }
                        if (! $record->is_finished) {
                            return '未作成';
                        }

                        return match ($record->createQueue?->status) {
                            'BEFORE' => '作成待ち', 'PROCESSING' => '作成中',
                            'FINISHED' => $record->createQueue->is_success ? '伝票無効／要確認' : '作成失敗',
                            default => '紐付け未確認',
                        };
                    })
                    ->url(fn (WmsShortageAllocation $record) => $this->printable($record)
                        ? rtrim(config('app.core_url'), '/').'/stocks/inventory/transfer/form/'.$record->createQueue->stock_transfer_id : null)
                    ->openUrlInNewTab(),
                TextColumn::make('finished_at')->label('完了日時')->dateTime('m/d H:i'),
                TextColumn::make('cancelled_at')->label('取消日時')->dateTime('m/d H:i')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_reason')->label('取消理由')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('cancellation')->label('取消の表示')->options(['exclude' => '取消を除く', 'include' => '取消を含む', 'only' => '取消のみ'])
                    ->default('exclude')->query(fn (Builder $query, array $data) => match ($data['value'] ?? 'exclude') {
                        'only' => $query->where('status', 'CANCELLED'), 'include' => $query, default => $query->activeRequest(),
                    }),
                SelectFilter::make('status')->label('作業状態')->options(self::statuses()),
                SelectFilter::make('target_warehouse_id')->label('横持ち出荷倉庫')
                    ->options(fn () => Warehouse::where('client_id', config('app.client_id'))->where('is_virtual', false)->orderBy('code')->get()
                        ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                    ->searchable(),
                Filter::make('shipment_date')->label('出荷日')->schema([DatePicker::make('from')->label('開始日'), DatePicker::make('until')->label('終了日')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('shipment_date', '>=', $date))
                        ->when($data['until'] ?? null, fn ($query, $date) => $query->whereDate('shipment_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('complete')->label('完了')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (WmsShortageAllocation $record) => Resource::canEdit($record) && in_array($record->status, ['RESERVED', 'PICKING']))
                    ->requiresConfirmation()->modalHeading('横持ち出荷の完了確認')
                    ->modalDescription(fn (WmsShortageAllocation $record) => "予定数 {$record->assign_qty}、ピック数 {$record->picked_qty}。不足がある場合は代理側欠品として確定します。")
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitActionLabel('完了を確定')->modalSubmitAction(fn ($action) => $action->color('danger'))->modalCancelActionLabel('完了せず閉じる')
                    ->action(function (WmsShortageAllocation $record) {
                        abort_unless(Resource::canEdit($record), 403);
                        app(ProxyShipmentLifecycleService::class)->complete($record->id, auth()->id());
                        Notification::make()->title('横持ち出荷を完了しました')->success()->send();
                    }),
                Action::make('cancel')->label('取消')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (WmsShortageAllocation $record) => Resource::canEdit($record))
                    ->modalHeading('横持ち出荷依頼を取り消します')
                    ->modalDescription(fn (WmsShortageAllocation $record) => "依頼ID {$record->id} / 商品CD {$record->shortage?->item?->code} {$record->shortage?->item?->name} / 予定数 {$record->assign_qty} / ピック数 {$record->picked_qty}。依頼を無効にします。元伝票・在庫・入力済み数量は変更せず、数量更新・倉庫移動のキューも作成しません。現物が未出荷であることを確認してください。")
                    ->schema([Textarea::make('cancel_reason')->label('取消理由')->required()->maxLength(500)])
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitActionLabel('取消を確定')->modalCancelActionLabel('取消せず閉じる')
                    ->action(function (WmsShortageAllocation $record, array $data) {
                        abort_unless(Resource::canEdit($record), 403);
                        app(ProxyShipmentLifecycleService::class)->cancel($record->id, auth()->id(), $data['cancel_reason']);
                        Notification::make()->title('依頼を取り消しました。元伝票は変更していません。')->success()->send();
                    }),
            ])
            ->bulkActions([])
            ->toolbarActions([
                Action::make('printTransfers')->label(new \Illuminate\Support\HtmlString('移動伝票PDF生成・印刷（<span x-text="getSelectedRecordsCount()">0</span>件）'))->icon('heroicon-o-printer')
                    ->extraAttributes(['x-data' => '{ printBusy: false }', 'x-bind:disabled' => 'printBusy || getSelectedRecordsCount() === 0'])
                    ->alpineClickHandler(<<<'JS'
                        (async () => {
                            if (printBusy || getSelectedRecordsCount() === 0) return;
                            printBusy = true;
                            const popup = window.open('', '_blank');
                            if (popup) popup.document.body.textContent = '印刷対象を確認しています…';
                            try {
                                $wire.set('isTrackingDeselectedTableRecords', isTrackingDeselectedRecords, false);
                                $wire.set('selectedTableRecords', [...selectedRecords], false);
                                $wire.set('deselectedTableRecords', [...deselectedRecords], false);
                                const url = await $wire.prepareTransferPrint(crypto.randomUUID());
                                if (!url) { if (popup) popup.close(); return; }
                                if (popup) { popup.opener = null; popup.location.href = url; }
                                else { $wire.dispatch('proxy-print-link', { url }); }
                            } catch (error) { if (popup) popup.close(); }
                            finally { printBusy = false; }
                        })()
                    JS),
            ]);
    }

    private static function statuses(): array
    {
        return ['PENDING' => '承認待ち', 'RESERVED' => '引当済み', 'PICKING' => 'ピッキング中', 'FULFILLED' => '完了', 'SHORTAGE' => '代理側欠品', 'CANCELLED' => '取消'];
    }
}
