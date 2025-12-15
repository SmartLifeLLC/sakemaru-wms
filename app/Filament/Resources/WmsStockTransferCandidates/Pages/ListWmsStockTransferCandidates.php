<?php

namespace App\Filament\Resources\WmsStockTransferCandidates\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Resources\WmsStockTransferCandidates\WmsStockTransferCandidateResource;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsStockTransferCandidate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsStockTransferCandidates extends ListRecords
{
    protected static string $resource = WmsStockTransferCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkApprove')
                ->label('一括承認')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('移動候補の一括承認')
                ->modalDescription('選択されたバッチの全候補を承認します。本当によろしいですか？')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                                ->distinct()
                                ->pluck('batch_code', 'batch_code');
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    $count = WmsStockTransferCandidate::where('batch_code', $data['batch_code'])
                        ->where('status', CandidateStatus::PENDING)
                        ->update(['status' => CandidateStatus::APPROVED]);

                    Notification::make()
                        ->title("{$count}件の移動候補を承認しました")
                        ->success()
                        ->send();
                }),

            Action::make('bulkExclude')
                ->label('一括除外')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('移動候補の一括除外')
                ->modalDescription('選択されたバッチの全候補を除外します。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsStockTransferCandidate::where('status', CandidateStatus::PENDING)
                                ->distinct()
                                ->pluck('batch_code', 'batch_code');
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    $count = WmsStockTransferCandidate::where('batch_code', $data['batch_code'])
                        ->where('status', CandidateStatus::PENDING)
                        ->update([
                            'status' => CandidateStatus::EXCLUDED,
                            'exclusion_reason' => '一括除外',
                        ]);

                    Notification::make()
                        ->title("{$count}件の移動候補を除外しました")
                        ->warning()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with([
                    'satelliteWarehouse',
                    'hubWarehouse',
                    'item',
                    'contractor',
                ])
                ->orderBy('batch_code', 'desc')
                ->orderBy('satellite_warehouse_id')
                ->orderBy('item_id')
            );
    }
}
