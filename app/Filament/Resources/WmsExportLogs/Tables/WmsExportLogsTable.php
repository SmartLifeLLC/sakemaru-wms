<?php

namespace App\Filament\Resources\WmsExportLogs\Tables;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\PaginationOptions;
use App\Services\Export\ExportService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsExportLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('resource_name')
                    ->label('対象画面')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('format')
                    ->label('形式')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ExportFormat ? $state->label() : $state)
                    ->color('gray')
                    ->width('80px'),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ExportStatus ? $state->label() : $state)
                    ->color(fn ($state) => $state instanceof ExportStatus ? $state->color() : 'gray')
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('file_name')
                    ->label('ファイル名')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('row_count')
                    ->label('件数')
                    ->numeric()
                    ->sortable()
                    ->width('80px'),

                TextColumn::make('human_file_size')
                    ->label('サイズ')
                    ->width('80px'),

                TextColumn::make('user.name')
                    ->label('実行者')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('実行日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('error_message')
                    ->label('エラー')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('format')
                    ->label('形式')
                    ->options(
                        collect(ExportFormat::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                            ->toArray()
                    ),

                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(
                        collect(ExportStatus::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->label()])
                            ->toArray()
                    ),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('ダウンロード')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->visible(fn ($record) => $record->isCompleted() && $record->file_path)
                    ->action(function ($record) {
                        $exportService = app(ExportService::class);

                        try {
                            return $exportService->getDownloadResponse($record);
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('ダウンロードに失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
