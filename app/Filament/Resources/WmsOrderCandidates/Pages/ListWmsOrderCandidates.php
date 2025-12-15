<?php

namespace App\Filament\Resources\WmsOrderCandidates\Pages;

use App\Enums\AutoOrder\CandidateStatus;
use App\Filament\Resources\WmsOrderCandidates\WmsOrderCandidateResource;
use App\Models\WmsOrderCandidate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsOrderCandidates extends ListRecords
{
    protected static string $resource = WmsOrderCandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkApprove')
                ->label('一括承認')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('発注候補の一括承認')
                ->modalDescription('選択されたバッチの全候補を承認します。本当によろしいですか？')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                                ->distinct()
                                ->pluck('batch_code', 'batch_code');
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    $count = WmsOrderCandidate::where('batch_code', $data['batch_code'])
                        ->where('status', CandidateStatus::PENDING)
                        ->update(['status' => CandidateStatus::APPROVED]);

                    Notification::make()
                        ->title("{$count}件の発注候補を承認しました")
                        ->success()
                        ->send();
                }),

            Action::make('bulkExclude')
                ->label('一括除外')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('発注候補の一括除外')
                ->modalDescription('選択されたバッチの全候補を除外します。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsOrderCandidate::where('status', CandidateStatus::PENDING)
                                ->distinct()
                                ->pluck('batch_code', 'batch_code');
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    $count = WmsOrderCandidate::where('batch_code', $data['batch_code'])
                        ->where('status', CandidateStatus::PENDING)
                        ->update([
                            'status' => CandidateStatus::EXCLUDED,
                            'exclusion_reason' => '一括除外',
                        ]);

                    Notification::make()
                        ->title("{$count}件の発注候補を除外しました")
                        ->warning()
                        ->send();
                }),

            Action::make('transmitOrders')
                ->label('発注送信')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('発注データの送信')
                ->modalDescription('承認済みの発注候補をJX-FINETまたはFTPで送信します。')
                ->schema([
                    Select::make('batch_code')
                        ->label('バッチコード')
                        ->options(function () {
                            return WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                                ->distinct()
                                ->pluck('batch_code', 'batch_code');
                        })
                        ->required(),
                ])
                ->action(function (array $data) {
                    // TODO: Phase 5で実装
                    Notification::make()
                        ->title('発注送信機能は Phase 5 で実装予定です')
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
                    'warehouse',
                    'item',
                    'contractor',
                ])
                ->orderBy('batch_code', 'desc')
                ->orderBy('warehouse_id')
                ->orderBy('item_id')
            );
    }
}
