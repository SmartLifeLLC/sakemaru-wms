<?php

namespace App\Filament\Resources\WmsOrderDataFiles\Tables;

use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\PaginationOptions;
use App\Filament\Resources\WmsOrderDataFiles\Pages\ListWmsOrderDataFiles;
use App\Mail\OrderDataMail;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderDataFile;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\PurchaseOrderPdfService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

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
                    ->label('実行CD')
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
                    ->label('サイズ')
                    ->state(fn ($record) => $record->file_size
                        ? number_format($record->file_size / 1024, 1).'KB'
                        : '-')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('csv_downloaded_at')
                    ->label('CSV')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('fax_downloaded_at')
                    ->label('FAX')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('mail_sent_at')
                    ->label('メール')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('batch_code')
                    ->label('実行CD')
                    ->options(function ($livewire): array {
                        $isTest = $livewire instanceof ListWmsOrderDataFiles
                            && $livewire->fileTypeTab === 'test';

                        return WmsOrderDataFile::query()
                            ->where('is_test', $isTest)
                            ->select('batch_code')
                            ->distinct()
                            ->orderByDesc('batch_code')
                            ->limit(50)
                            ->pluck('batch_code', 'batch_code')
                            ->toArray();
                    })
                    ->default(function ($livewire): ?string {
                        $isTest = $livewire instanceof ListWmsOrderDataFiles
                            && $livewire->fileTypeTab === 'test';

                        return WmsOrderDataFile::query()
                            ->where('is_test', $isTest)
                            ->orderByDesc('batch_code')
                            ->value('batch_code');
                    })
                    ->searchable(),

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
                // CSV
                Action::make('downloadCsv')
                    ->label('CSV')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->action(function (WmsOrderDataFile $record) {
                        if (! $record->file_path) {
                            Notification::make()
                                ->title('CSVファイルが見つかりません')
                                ->danger()
                                ->send();

                            return;
                        }

                        $service = app(OrderDataFileService::class);
                        $url = $service->getDownloadUrl($record);

                        if (! $url) {
                            Notification::make()
                                ->title('ダウンロードURLの生成に失敗しました')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->markAsCsvDownloaded(auth()->id());

                        return redirect($url);
                    }),

                // FAX
                Action::make('downloadFax')
                    ->label('FAX')
                    ->icon('heroicon-o-document')
                    ->color('success')
                    ->action(function (WmsOrderDataFile $record) {
                        try {
                            // FAX PDFが未生成の場合は生成
                            if (! $record->fax_file_path) {
                                $pdfService = app(PurchaseOrderPdfService::class);
                                $pdfService->generateAndStore($record);
                                $record->refresh();
                            }

                            if (! $record->fax_file_path) {
                                Notification::make()
                                    ->title('FAX PDFの生成に失敗しました')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $url = Storage::disk('s3')->temporaryUrl($record->fax_file_path, now()->addHour());

                            $record->markAsFaxDownloaded(auth()->id());

                            Notification::make()
                                ->title('FAX PDFを生成しました')
                                ->success()
                                ->send();

                            return redirect($url);
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('エラーが発生しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                // メール
                Action::make('sendMail')
                    ->label('メール')
                    ->icon('heroicon-o-envelope')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('メール送信')
                    ->modalDescription('発注データをメールで送信します')
                    ->modalSubmitActionLabel('送信')
                    ->schema([
                        TextInput::make('mail_to')
                            ->label('送信先メールアドレス')
                            ->email()
                            ->required()
                            ->default(fn (WmsOrderDataFile $record) => $record->mail_to ?? $record->contractor?->email ?? ''),

                        CheckboxList::make('attachments')
                            ->label('添付ファイル')
                            ->options([
                                'csv' => 'CSV（発注データ）',
                                'fax' => 'FAX（発注書PDF）',
                            ])
                            ->default(['csv', 'fax'])
                            ->required(),
                    ])
                    ->action(function (WmsOrderDataFile $record, array $data) {
                        $email = $data['mail_to'];

                        try {
                            $attachments = $data['attachments'] ?? [];
                            $attachCsv = in_array('csv', $attachments);
                            $attachFax = in_array('fax', $attachments);

                            // FAX PDFが未生成で添付が必要な場合は生成
                            if ($attachFax && ! $record->fax_file_path) {
                                $pdfService = app(PurchaseOrderPdfService::class);
                                $pdfService->generateAndStore($record);
                                $record->refresh();
                            }

                            // メール送信
                            Mail::to($email)->send(new OrderDataMail($record, $attachCsv, $attachFax));

                            $record->markAsMailSent(auth()->id(), $email);

                            Notification::make()
                                ->title('メールを送信しました')
                                ->body("送信先: {$email}")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('メール送信に失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
