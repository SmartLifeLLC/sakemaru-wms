<?php

namespace App\Filament\Resources\WmsIncomingCompletedSummary\Pages;

use App\Enums\AutoOrder\IncomingScheduleStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingCompletedSummary\WmsIncomingCompletedSummaryResource;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsOrderIncomingSchedule;
use App\Services\AutoOrder\IncomingTransmissionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingCompletedSummary extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingCompletedSummaryResource::class;

    protected ?array $presetViewContractorData = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transmitPurchaseByContractor')
                ->label(fn (): string => $this->getPurchaseTransmissionActionLabel())
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->modalHeading(fn (): string => $this->getPurchaseTransmissionActionLabel())
                ->modalDescription(fn (): string => $this->getPurchaseTransmissionTargetLabel().'の未送信入荷完了データを基幹システムの仕入キューに登録します。同一の倉庫・仕入先・伝票番号・入荷日ごとに1伝票としてまとめられます。登録後はデータの修正ができなくなります。')
                ->requiresConfirmation()
                ->modalSubmitActionLabel('全連携')
                ->action(function (): void {
                    $this->transmitIncomingSchedulesByContractor();
                }),
        ];
    }

    public function transmitIncomingSchedulesByContractor(): void
    {
        $warehouseId = $this->getPurchaseTransmissionWarehouseId();

        if ($warehouseId === null) {
            Notification::make()
                ->title('倉庫を選択してください')
                ->body('仕入データ全連携は選択中の倉庫を対象に実行します。倉庫を選択してから再実行してください。')
                ->warning()
                ->send();

            return;
        }

        try {
            $result = app(IncomingTransmissionService::class)
                ->transmitConfirmedIncomings(
                    warehouseId: $warehouseId,
                    contractorId: $this->getPurchaseTransmissionContractorId(),
                );

            if ($result['success']) {
                Notification::make()
                    ->title('仕入キューに登録しました')
                    ->body("キュー: {$result['queue_count']}件 / 入荷データ: {$result['schedule_count']}件")
                    ->success()
                    ->send();
            } else {
                $errors = collect($result['errors'] ?? [])
                    ->map(fn ($error): string => is_array($error) ? ($error['error'] ?? json_encode($error, JSON_UNESCAPED_UNICODE)) : (string) $error)
                    ->take(5)
                    ->implode("\n");

                Notification::make()
                    ->title('一部エラーが発生しました')
                    ->body("成功: {$result['schedule_count']}件 / エラー: ".count($result['errors']).($errors !== '' ? "\n{$errors}" : ''))
                    ->warning()
                    ->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('登録エラー')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => static::applySummaryQuery($query));
    }

    public static function applySummaryQuery(Builder $query): Builder
    {
        $table = (new WmsOrderIncomingSchedule)->getTable();

        return $query
            ->select([
                "{$table}.warehouse_id",
                "{$table}.contractor_id",
            ])
            ->selectRaw("MIN({$table}.id) as id")
            ->selectRaw('COUNT(*) as summary_detail_count')
            ->selectRaw("COUNT(DISTINCT {$table}.item_id) as summary_item_count")
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM({$table}.slip_number), '')) as summary_slip_count")
            ->selectRaw("MIN({$table}.expected_arrival_date) as summary_expected_from")
            ->selectRaw("MAX({$table}.expected_arrival_date) as summary_expected_until")
            ->selectRaw("MIN({$table}.actual_arrival_date) as summary_actual_from")
            ->selectRaw("MAX({$table}.actual_arrival_date) as summary_actual_until")
            ->selectRaw("MAX({$table}.confirmed_at) as summary_last_confirmed_at")
            ->selectRaw("SUM(CASE WHEN {$table}.purchase_queue_id IS NULL THEN 1 ELSE 0 END) as summary_untransmitted_count")
            ->selectRaw("SUM(CASE WHEN {$table}.purchase_queue_id IS NULL THEN 0 ELSE 1 END) as summary_transmitted_count")
            ->groupBy("{$table}.warehouse_id", "{$table}.contractor_id");
    }

    protected function getContractorDataForPresetViews(): array
    {
        if ($this->presetViewContractorData !== null) {
            return $this->presetViewContractorData;
        }

        $warehouseId = auth()->user()?->getSelectedWarehouseId();
        $cacheKey = 'incoming_completed_summary_contractors_'.($warehouseId ?: 'none');
        $this->presetViewContractorData = cache()->remember($cacheKey, 30, function () use ($warehouseId) {
            if (! $warehouseId) {
                return [
                    'warehouse_id' => null,
                    'ids' => [],
                    'contractors' => collect(),
                ];
            }

            $contractorIds = WmsOrderIncomingSchedule::query()
                ->where('status', IncomingScheduleStatus::CONFIRMED)
                ->withoutTransferSource()
                ->where('warehouse_id', $warehouseId)
                ->whereNotNull('contractor_id')
                ->distinct()
                ->pluck('contractor_id')
                ->map(fn ($id): int => (int) $id)
                ->toArray();

            $contractorIds = collect($contractorIds)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            $contractors = Contractor::whereIn('id', $contractorIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);

            return [
                'warehouse_id' => (int) $warehouseId,
                'ids' => $contractorIds,
                'contractors' => $contractors,
            ];
        });

        return $this->presetViewContractorData;
    }

    public function getPresetViews(): array
    {
        $contractorData = $this->getContractorDataForPresetViews();
        $warehouseId = $contractorData['warehouse_id'];
        $contractors = $contractorData['contractors'];

        $views = [
            'default' => PresetView::make()
                ->when(
                    $warehouseId,
                    fn (PresetView $view): PresetView => $view->modifyQueryUsing(
                        fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId)
                    )
                )
                ->favorite()
                ->label('全て')
                ->default(),
        ];

        foreach ($contractors as $contractor) {
            $label = filled($contractor->code)
                ? "[{$contractor->code}]{$contractor->name}"
                : $contractor->name;

            $views["contractor_{$contractor->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->when($warehouseId, fn (Builder $query): Builder => $query->where('warehouse_id', $warehouseId))
                    ->where('contractor_id', $contractor->id))
                ->favorite()
                ->label($label);
        }

        return $views;
    }

    private function getPurchaseTransmissionWarehouseId(): ?int
    {
        $warehouseId = auth()->user()?->getSelectedWarehouseId();

        return $warehouseId ? (int) $warehouseId : null;
    }

    private function getPurchaseTransmissionContractorId(): ?int
    {
        $view = $this->activePresetView ?: $this->currentPresetView;

        if (! is_string($view)) {
            return null;
        }

        if (! preg_match('/^contractor_(\d+)$/', $view, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    private function getPurchaseTransmissionActionLabel(): string
    {
        $contractor = $this->getPurchaseTransmissionContractor();

        if (! $contractor) {
            return '全発注先 仕入データ全連携';
        }

        return "{$contractor->name} 仕入データ全連携";
    }

    private function getPurchaseTransmissionTargetLabel(): string
    {
        $contractor = $this->getPurchaseTransmissionContractor();

        if (! $contractor) {
            return '選択中倉庫の全発注先';
        }

        return filled($contractor->code)
            ? "[{$contractor->code}]{$contractor->name}"
            : $contractor->name;
    }

    private function getPurchaseTransmissionContractor(): ?Contractor
    {
        $contractorId = $this->getPurchaseTransmissionContractorId();

        if (! $contractorId) {
            return null;
        }

        $contractorData = $this->getContractorDataForPresetViews();

        return $contractorData['contractors']->firstWhere('id', $contractorId)
            ?? Contractor::find($contractorId);
    }
}
