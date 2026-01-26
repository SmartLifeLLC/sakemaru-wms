<?php

namespace App\Filament\Resources\WmsOrderDocuments\Tables;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\PaginationOptions;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderTransmissionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class WmsOrderDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('batch_code')
                    ->label('バッチコード')
                    ->state(function ($record) {
                        return \Carbon\Carbon::createFromFormat('YmdHis', $record->batch_code)->format('m/d H:i');
                    })
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionDocumentStatus $state): string => $state->getLabel())
                    ->color(fn (TransmissionDocumentStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor
                        ? "[{$record->contractor->code}]{$record->contractor->name}"
                        : '-')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->state(fn ($record) => $record->warehouse
                        ? "[{$record->warehouse->code}]{$record->warehouse->name}"
                        : '-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('order_count')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('record_count')
                    ->label('明細数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('file_size')
                    ->label('ファイルサイズ')
                    ->state(fn ($record) => $record->file_size
                        ? number_format($record->file_size / 1024, 1).'KB'
                        : '-')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('encoding')
                    ->label('文字コード')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('transmitted_at')
                    ->label('送信日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(fn () => collect(TransmissionDocumentStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->relationship('contractor', 'name'),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('ダウンロード')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->action(function (WmsOrderJxDocument $record) {
                        if (! $record->file_path) {
                            Notification::make()
                                ->title('ファイルが見つかりません')
                                ->danger()
                                ->send();

                            return;
                        }

                        // S3から直接ダウンロード用のURLを生成
                        $service = app(OrderTransmissionService::class);
                        $url = $service->getDownloadUrl($record);

                        if (! $url) {
                            Notification::make()
                                ->title('ダウンロードURLの生成に失敗しました')
                                ->danger()
                                ->send();

                            return;
                        }

                        // JavaScriptでダウンロード
                        return redirect($url);
                    }),

                Action::make('transmit')
                    ->label('JX送信')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === TransmissionDocumentStatus::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('JX-FINET送信')
                    ->modalDescription('このファイルをJX-FINETで送信しますか？')
                    ->action(function (WmsOrderJxDocument $record) {
                        $service = app(OrderTransmissionService::class);
                        $result = $service->transmitOrderFilesViaJx($record->batch_code);

                        if ($result['success']) {
                            Notification::make()
                                ->title('送信しました')
                                ->success()
                                ->send();
                        } else {
                            $errorMsg = implode(', ', array_map(fn ($e) => $e['error'] ?? '', $result['errors']));
                            Notification::make()
                                ->title('送信に失敗しました')
                                ->body($errorMsg)
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('delete')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === TransmissionDocumentStatus::DRAFT)
                    ->requiresConfirmation()
                    ->action(function (WmsOrderJxDocument $record) {
                        // S3からファイル削除
                        if ($record->file_path && Storage::disk('s3')->exists($record->file_path)) {
                            Storage::disk('s3')->delete($record->file_path);
                        }

                        $record->delete();

                        Notification::make()
                            ->title('削除しました')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
