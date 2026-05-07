<?php

namespace App\Filament\Resources\Waves\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Services\PickingList\PickingListPdfService;
use App\Services\PickingList\PickingListService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WavesTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('wave_no')
                    ->label('波動番号')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.warehouse.code')
                    ->label('倉庫コード')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.warehouse.name')
                    ->label('倉庫名')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.code')
                    ->label('配送コースコード')
                    ->sortable(),

                TextColumn::make('waveSetting.deliveryCourse.name')
                    ->label('配送コース名')
                    ->sortable(),

                TextColumn::make('shipping_date')
                    ->label('出荷日')
                    ->date('Y年m月d日')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('出荷状況')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'PENDING' => '未出荷',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品あり',
                        'COMPLETED' => '出荷完了',
                        'CLOSED' => 'クローズ',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'PENDING' => 'gray',
                        'PICKING' => 'info',
                        'SHORTAGE' => 'warning',
                        'COMPLETED' => 'success',
                        'CLOSED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('波動生成時刻')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = mb_convert_kana($search, 'as');

                        return Warehouse::query()
                            ->where('is_active', true)
                            ->where(fn ($q) => $q
                                ->where('code', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('code')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}]{$w->name}"])
                            ->toArray();
                    })
                    ->getOptionLabelUsing(fn ($value) => Warehouse::find($value)?->name)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['value'],
                            fn (Builder $query, $warehouseId): Builder => $query->whereHas(
                                'waveSetting.deliveryCourse',
                                fn (Builder $q) => $q->where('warehouse_id', $warehouseId)
                            )
                        );
                    }),

                Filter::make('shipping_date')
                    ->label('出荷日')
                    ->form([
                        DatePicker::make('shipping_date')
                            ->label('出荷日')
                            ->default(ClientSetting::systemDateYMD()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['shipping_date'], fn (Builder $q, $date) => $q->where('shipping_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['shipping_date']) {
                            return null;
                        }

                        return '出荷日: '.\Carbon\Carbon::parse($data['shipping_date'])->format('Y年m月d日');
                    }),

                SelectFilter::make('status')
                    ->label('出荷状況')
                    ->multiple()
                    ->options([
                        'PENDING' => '未出荷',
                        'PICKING' => 'ピッキング中',
                        'SHORTAGE' => '欠品あり',
                        'COMPLETED' => '出荷完了',
                        'CLOSED' => 'クローズ',
                    ]),
            ])
            ->recordActions([
                Action::make('printPrimaryList')
                    ->label('1次リスト')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->modalHeading('ピッキングリスト出力')
                    ->modalWidth('sm')
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('1次リスト出力')->color('primary'))
                    ->extraModalFooterActions(fn ($action) => [
                        $action->makeModalSubmitAction('printShortage', ['shortage' => true])->label('欠品リスト出力')->color('danger'),
                    ])
                    ->modalCancelActionLabel('閉じる')
                    ->schema([
                        Toggle::make('include_past')
                            ->label('過去の伝票も出力')
                            ->default(false),
                        Toggle::make('include_delivered')
                            ->label('配送済みも出力')
                            ->default(false),
                    ])
                    ->action(function ($record, array $data, array $arguments) {
                        try {
                            $service = new PickingListService;
                            $pdfService = new PickingListPdfService;

                            if ($arguments['shortage'] ?? false) {
                                $result = $service->generateShortageList($record->id);

                                if (empty($result['items'])) {
                                    Notification::make()->title('欠品はありません')->success()->send();

                                    return;
                                }

                                $pdf = $pdfService->renderShortagePdf($result);

                                return response()->streamDownload(
                                    fn () => print ($pdf),
                                    "shortage-list-1st-{$record->wave_no}.pdf",
                                    ['Content-Type' => 'application/pdf']
                                );
                            }

                            $result = $service->generatePrimaryList(
                                $record->id,
                                $data['include_past'] ?? false,
                                $data['include_delivered'] ?? false,
                            );

                            if (empty($result['items'])) {
                                Notification::make()->title('ピッキング明細がありません')->warning()->send();

                                return;
                            }

                            $pdf = $pdfService->renderPrimaryPdf($result);

                            return response()->streamDownload(
                                fn () => print ($pdf),
                                "picking-list-1st-{$record->wave_no}.pdf",
                                ['Content-Type' => 'application/pdf']
                            );
                        } catch (\Exception $e) {
                            Notification::make()->title('PDF生成に失敗しました')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('printTertiaryList')
                    ->label('3次リスト')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('info')
                    ->action(function ($record) {
                        try {
                            $service = new PickingListService;
                            $dataList = $service->generateTertiaryList($record->id);

                            if (empty($dataList)) {
                                Notification::make()->title('ピッキング明細がありません')->warning()->send();

                                return;
                            }

                            $pdfService = new PickingListPdfService;
                            $pdf = $pdfService->renderTertiaryPdf($dataList);

                            return response()->streamDownload(
                                fn () => print ($pdf),
                                "picking-list-3rd-{$record->wave_no}.pdf",
                                ['Content-Type' => 'application/pdf']
                            );
                        } catch (\Exception $e) {
                            Notification::make()->title('PDF生成に失敗しました')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
