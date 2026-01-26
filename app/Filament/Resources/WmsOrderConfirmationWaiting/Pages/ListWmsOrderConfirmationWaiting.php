<?php

namespace App\Filament\Resources\WmsOrderConfirmationWaiting\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsOrderConfirmationWaiting\WmsOrderConfirmationWaitingResource;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderExecutionService;
use App\Services\AutoOrder\OrderTransmissionService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderConfirmationWaiting extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsOrderConfirmationWaitingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateOrderFiles')
                ->label('発注送信データ生成')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->modalHeading('発注送信データの生成')
                ->modalDescription('承認済みの発注候補から発注ファイルを生成します（テスト用）。確定後に再度生成されます。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                                ->distinct()
                                ->orderBy('batch_code', 'desc')
                                ->pluck('batch_code')
                                ->mapWithKeys(fn ($code) => [
                                    $code => \Carbon\Carbon::createFromFormat('YmdHis', $code)->format('Y/m/d H:i:s'),
                                ]);
                        })
                        ->required()
                        ->helperText('ファイルを生成するバッチを選択してください'),
                ])
                ->action(function (array $data) {
                    $service = app(OrderTransmissionService::class);

                    try {
                        $result = $service->generateOrderFilesForApproved($data['batch_code']);

                        if ($result['success']) {
                            $fileCount = count($result['files']);
                            $totalOrders = $result['total_orders'];
                            Notification::make()
                                ->title('発注送信データを生成しました')
                                ->body("{$fileCount}件のファイル（発注 {$totalOrders}件）を生成しました。発注送信ファイル画面で確認・ダウンロードできます。")
                                ->success()
                                ->send();
                        } else {
                            $errorMsg = implode(', ', $result['errors']);
                            Notification::make()
                                ->title('生成に失敗しました')
                                ->body($errorMsg ?: '対象がありません')
                                ->danger()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('エラーが発生しました')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('confirmAll')
                ->label('発注確定')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->modalHeading('発注確定')
                ->modalDescription('承認済みの発注候補を確定し、入庫予定を作成します。同時に発注送信データも生成されます。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            // APPROVEDのバッチのみ表示
                            return WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                                ->distinct()
                                ->orderBy('batch_code', 'desc')
                                ->pluck('batch_code')
                                ->mapWithKeys(fn ($code) => [
                                    $code => \Carbon\Carbon::createFromFormat('YmdHis', $code)->format('Y/m/d H:i:s'),
                                ]);
                        })
                        ->required()
                        ->helperText('確定するバッチを選択してください'),
                ])
                ->action(function (array $data) {
                    $executionService = app(OrderExecutionService::class);
                    $transmissionService = app(OrderTransmissionService::class);

                    try {
                        // 1. 発注確定
                        $schedules = $executionService->confirmBatch($data['batch_code'], auth()->id());

                        if ($schedules->isEmpty()) {
                            Notification::make()
                                ->title('確定対象がありません')
                                ->body('選択したバッチに承認済みの発注候補がありません')
                                ->warning()
                                ->send();

                            return;
                        }

                        // 2. 発注送信データ生成
                        $result = $transmissionService->generateOrderFiles($data['batch_code']);
                        $fileCount = count($result['files'] ?? []);

                        Notification::make()
                            ->title('発注を確定しました')
                            ->body("入庫予定 {$schedules->count()}件 を作成しました。発注送信ファイル {$fileCount}件 を生成しました。")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('エラーが発生しました')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }

    public function getPresetViews(): array
    {
        // ユーザーのデフォルト倉庫を取得
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // 発注確定待ち（APPROVED）に存在する倉庫を取得してタブを生成
        $warehouseIds = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
            ->distinct()
            ->pluck('warehouse_id')
            ->toArray();

        // デフォルト倉庫が発注確定待ちに存在するかチェック
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? Warehouse::find($userDefaultWarehouseId) : null;

        // プリセットビュー構築
        $views = [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(! $hasDefaultWarehouse),
        ];

        // デフォルト倉庫があればその倉庫タブを追加
        if ($defaultWarehouse) {
            $views["warehouse_{$defaultWarehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $defaultWarehouse->id))
                ->favorite()
                ->label($defaultWarehouse->name)
                ->default();
        }

        return $views;
    }
}
