<?php

namespace App\Filament\Resources\WmsOrderDataFiles\Tables;

use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\PaginationOptions;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderDataFile;
use App\Services\AutoOrder\OrderDataFileService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsOrderDataFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->extraAttributes(['class' => 'order-data-files-table sticky-actions'])
            ->columns([
                TextColumn::make('batch_code')
                    ->label('バッチコード')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (OrderDataFileStatus $state): string => $state->getLabel())
                    ->color(fn (OrderDataFileStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷予定日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->width('120px'),

                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->searchable()
                    ->alignCenter()
                    ->width('50px'),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->searchable()
                    ->grow(),

                TextColumn::make('order_count')
                    ->label('発注数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('total_quantity')
                    ->label('合計数量')
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

                TextColumn::make('downloaded_at')
                    ->label('ダウンロード日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('downloadedByUser.name')
                    ->label('ダウンロードユーザー')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('ステータス')
                    ->options(fn () => collect(OrderDataFileStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])),

                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::query()
                        ->where('is_active', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"]))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    }),

                SelectFilter::make('contractor_id')
                    ->label('発注先')
                    ->multiple()
                    ->options(fn () => Contractor::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"]))
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Contractor::query()
                            ->where(function ($query) use ($search) {
                                $query->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'like', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}]{$c->name}"])
                            ->toArray();
                    }),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('ダウンロード')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->action(function (WmsOrderDataFile $record) {
                        if (! $record->file_path) {
                            Notification::make()
                                ->title('ファイルが見つかりません')
                                ->danger()
                                ->send();

                            return;
                        }

                        // S3から直接ダウンロード用のURLを生成
                        $service = app(OrderDataFileService::class);
                        $url = $service->getDownloadUrl($record);

                        if (! $url) {
                            Notification::make()
                                ->title('ダウンロードURLの生成に失敗しました')
                                ->danger()
                                ->send();

                            return;
                        }

                        // ダウンロード済みとしてマーク
                        $record->markAsDownloaded(auth()->id());

                        return redirect($url);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
