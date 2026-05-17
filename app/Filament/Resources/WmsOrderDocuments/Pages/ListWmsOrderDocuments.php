<?php

namespace App\Filament\Resources\WmsOrderDocuments\Pages;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderDocuments\WmsOrderDocumentResource;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderTransmissionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderDocuments extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->getCancelAllPendingJxAction(),
                $this->getDownloadCorrectionPreviewCsvAction(),
                $this->getGenerateCorrectionResendJxAction(),
            ])
                ->label('管理者メニュー')
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->button(),
        ];
    }

    public function table(\Filament\Tables\Table $table): \Filament\Tables\Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['warehouse', 'contractor', 'jobControl.createdByUser'])
                ->orderBy('created_at', 'desc')
            );
    }

    public function getPresetViews(): array
    {
        $pendingCount = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
            ->count();
        $transmittedCount = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::TRANSMITTED)
            ->count();
        $cancelledCount = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::CANCELLED)
            ->count();
        $testCount = WmsOrderJxDocument::whereIn('status', [
            TransmissionDocumentStatus::TEST,
            TransmissionDocumentStatus::DRAFT,
        ])
            ->count();

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TransmissionDocumentStatus::PENDING))
                ->favorite()
                ->label("送信前 ({$pendingCount})")
                ->default(),

            'transmitted' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TransmissionDocumentStatus::TRANSMITTED))
                ->favorite()
                ->label("送信済 ({$transmittedCount})"),

            'cancelled' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TransmissionDocumentStatus::CANCELLED))
                ->favorite()
                ->label("送信取消 ({$cancelledCount})"),

            'test' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    TransmissionDocumentStatus::TEST,
                    TransmissionDocumentStatus::DRAFT,
                ]))
                ->favorite()
                ->label("テスト ({$testCount})"),

            'all' => PresetView::make()
                ->favorite()
                ->label('全て'),
        ];
    }

    private function getCancelAllPendingJxAction(): Action
    {
        return Action::make('cancelAllPendingJxDocuments')
            ->label('全件送信取消')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('送信前JXデータを全件送信取消')
            ->modalDescription(function () {
                $count = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
                    ->count();

                return "送信待ちのJXデータ {$count} 件を送信せずに「送信取消」にします。この操作は元に戻せません。";
            })
            ->modalSubmitActionLabel('全件送信取消を実行')
            ->modalCancelActionLabel('取消せず閉じる')
            ->requiresConfirmation()
            ->action(function () {
                $count = WmsOrderJxDocument::where('status', TransmissionDocumentStatus::PENDING)
                    ->update([
                        'status' => TransmissionDocumentStatus::CANCELLED,
                    ]);

                Notification::make()
                    ->title("送信取消完了（{$count}件）")
                    ->body('送信待ちのJXデータを全件送信取消にしました')
                    ->success()
                    ->send();
            });
    }

    private function getDownloadCorrectionPreviewCsvAction(): Action
    {
        return Action::make('downloadCorrectionPreviewCsv')
            ->label('修正CSV生成')
            ->icon('heroicon-o-document-arrow-down')
            ->color('info')
            ->modalHeading('修正再送CSV生成')
            ->modalDescription('送信済みの当日発注確定分から、再送前確認用CSVを生成します。')
            ->modalSubmitActionLabel('CSV生成')
            ->modalCancelActionLabel('生成せず閉じる')
            ->schema(static::correctionResendSchema())
            ->action(function (array $data) {
                try {
                    $preview = app(OrderTransmissionService::class)->buildCorrectionResendPreviewCsv(
                        (int) $data['contractor_id'],
                        $data['transmitted_date']
                    );

                    return response()->streamDownload(
                        fn () => print $preview['content'],
                        $preview['filename'],
                        ['Content-Type' => 'text/csv; charset=UTF-8']
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('CSV生成に失敗しました')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function getGenerateCorrectionResendJxAction(): Action
    {
        return Action::make('generateCorrectionResendJx')
            ->label('修正JX生成')
            ->icon('heroicon-o-document-plus')
            ->color('danger')
            ->modalHeading('修正再送JX生成')
            ->modalDescription('送信済みの当日発注確定分を1つのJXファイルにまとめて生成します。生成後、この画面で対象行を選択して「選択JX送信」から送信してください。')
            ->modalSubmitActionLabel('JXファイル生成')
            ->modalCancelActionLabel('生成せず閉じる')
            ->schema(static::correctionResendSchema())
            ->action(function (array $data) {
                $result = app(OrderTransmissionService::class)->generateCorrectionResendFiles(
                    (int) $data['contractor_id'],
                    $data['transmitted_date']
                );

                if ($result['success']) {
                    $documentIds = collect($result['files'])
                        ->pluck('document_id')
                        ->filter()
                        ->implode(', ');

                    Notification::make()
                        ->title('修正JXファイルを生成しました')
                        ->body("発注数: {$result['total_orders']} / 伝票ID: {$documentIds}")
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('修正JX生成に失敗しました')
                    ->body(implode("\n", $result['errors'] ?? ['生成失敗']))
                    ->danger()
                    ->send();
            });
    }

    private static function correctionResendSchema(): array
    {
        return [
            Select::make('contractor_id')
                ->label('仕入先')
                ->options(fn () => static::jxContractorOptions())
                ->searchable()
                ->required(),

            DatePicker::make('transmitted_date')
                ->label('送信日')
                ->default(now())
                ->required(),
        ];
    }

    private static function jxContractorOptions(): array
    {
        $contractorIds = static::jxTransmissionContractorIds();

        return Contractor::query()
            ->whereIn('id', $contractorIds)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Contractor $contractor) => [
                $contractor->id => "[{$contractor->code}]{$contractor->name}",
            ])
            ->all();
    }

    private static function jxTransmissionContractorIds(): array
    {
        $settingContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET->value)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $generator = app(OrderTransmissionService::class)->getGenerator();
        $generatorContractorIds = $generator?->getJxTransmissionContractorIds() ?? [];
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];

        return array_values(array_unique(array_merge(
            $settingContractorIds,
            array_map('intval', $generatorContractorIds),
            array_map('intval', array_values($mapping))
        )));
    }
}
