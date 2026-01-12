<?php

namespace App\Filament\Resources;

use App\Enums\EMenu;
use App\Enums\PaginationOptions;
use App\Filament\Resources\WmsJxTransmissionLogResource\Pages;
use App\Models\WmsJxTransmissionLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WmsJxTransmissionLogResource extends Resource
{
    protected static ?string $model = WmsJxTransmissionLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-up-down';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_JX_TRANSMISSION_LOGS->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_JX_TRANSMISSION_LOGS->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_JX_TRANSMISSION_LOGS->sort();
    }

    public static function getModelLabel(): string
    {
        return 'JX送受信履歴';
    }

    public static function getPluralModelLabel(): string
    {
        return 'JX送受信履歴';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('transmitted_at', 'desc')
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->striped()
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('jxSetting.name')
                    ->label('JX設定')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('transmitted_at')
                    ->label('送受信日時')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('方向')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::DIRECTION_SEND => 'info',
                        WmsJxTransmissionLog::DIRECTION_RECEIVE => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (WmsJxTransmissionLog $record) => $record->direction_label),
                TextColumn::make('operation_type')
                    ->label('操作タイプ')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::OPERATION_PUT => 'warning',
                        WmsJxTransmissionLog::OPERATION_GET => 'primary',
                        WmsJxTransmissionLog::OPERATION_CONFIRM => 'gray',
                        default => 'gray',
                    }),
                IconColumn::make('status')
                    ->label('結果')
                    ->icon(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::STATUS_SUCCESS => 'heroicon-o-check-circle',
                        WmsJxTransmissionLog::STATUS_FAILURE => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        WmsJxTransmissionLog::STATUS_SUCCESS => 'success',
                        WmsJxTransmissionLog::STATUS_FAILURE => 'danger',
                        default => 'gray',
                    })
                    ->alignCenter(),
                TextColumn::make('message_id')
                    ->label('メッセージID')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($state) => $state),
                TextColumn::make('document_type')
                    ->label('文書タイプ')
                    ->alignCenter(),
                TextColumn::make('data_size')
                    ->label('サイズ')
                    ->formatStateUsing(fn (?int $state) => $state ? number_format($state).' bytes' : '-')
                    ->alignRight(),
                TextColumn::make('http_code')
                    ->label('HTTP')
                    ->badge()
                    ->color(fn (?int $state): string => match (true) {
                        $state === null => 'gray',
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 400 => 'danger',
                        default => 'warning',
                    })
                    ->alignCenter(),
                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('file_path')
                    ->label('ファイルパス')
                    ->limit(30)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sender_id')
                    ->label('送信者ID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('receiver_id')
                    ->label('受信者ID')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('方向')
                    ->options([
                        WmsJxTransmissionLog::DIRECTION_SEND => '送信',
                        WmsJxTransmissionLog::DIRECTION_RECEIVE => '受信',
                    ]),
                SelectFilter::make('operation_type')
                    ->label('操作タイプ')
                    ->options([
                        WmsJxTransmissionLog::OPERATION_PUT => 'PutDocument',
                        WmsJxTransmissionLog::OPERATION_GET => 'GetDocument',
                        WmsJxTransmissionLog::OPERATION_CONFIRM => 'ConfirmDocument',
                    ]),
                SelectFilter::make('status')
                    ->label('結果')
                    ->options([
                        WmsJxTransmissionLog::STATUS_SUCCESS => '成功',
                        WmsJxTransmissionLog::STATUS_FAILURE => '失敗',
                    ]),
                Filter::make('transmitted_at')
                    ->form([
                        DatePicker::make('from')
                            ->label('開始日'),
                        DatePicker::make('until')
                            ->label('終了日'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('transmitted_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('transmitted_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('ダウンロード')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (WmsJxTransmissionLog $record) => route('jx-transmission-logs.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (WmsJxTransmissionLog $record) => ! empty($record->file_path)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWmsJxTransmissionLogs::route('/'),
        ];
    }
}
