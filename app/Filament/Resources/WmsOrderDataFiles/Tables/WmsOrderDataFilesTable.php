<?php

namespace App\Filament\Resources\WmsOrderDataFiles\Tables;

use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Resources\WmsOrderDataFiles\Pages\ListWmsOrderDataFiles;
use App\Mail\OrderDataMail;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderDataFile;
use App\Services\AutoOrder\OrderDataFileService;
use App\Services\AutoOrder\PurchaseOrderPdfService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WmsOrderDataFilesTable
{
    use HasExportAction;

    /**
     * テンプレート内の$$VAR_XXX$$を実データに置換
     */
    private static function replaceMailVariables(?string $template, WmsOrderDataFile $record): ?string
    {
        if (! $template) {
            return null;
        }

        $variables = [
            '$$VAR_CONTRACTOR_NAME$$' => $record->contractor?->name ?? '発注先',
            '$$VAR_WAREHOUSE_NAME$$' => $record->warehouse?->name ?? '倉庫',
            '$$VAR_ORDER_DATE$$' => $record->order_date->format('Y年m月d日'),
            '$$VAR_ORDER_DATE_SHORT$$' => $record->order_date->format('Y/m/d'),
            '$$VAR_EXPECTED_ARRIVAL_DATE$$' => $record->expected_arrival_date?->format('Y年m月d日') ?? '未定',
            '$$VAR_ORDER_COUNT$$' => number_format($record->order_count),
            '$$VAR_TOTAL_QUANTITY$$' => number_format($record->total_quantity),
            '$$VAR_ATTACHMENTS$$' => "・発注データ（CSV形式）\n・発注書（PDF形式）",
        ];

        return str_replace(array_keys($variables), array_values($variables), $template);
    }

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
                    ->width('50px')
                    ->placeholder('-'),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->searchable()
                    ->width('120px')
                    ->placeholder('全倉庫'),

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

                TextColumn::make('is_mail_order')
                    ->label('送信方式')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'メール送信' : '手動送信')
                    ->color(fn ($state): string => $state ? 'success' : 'gray')
                    ->alignCenter(),

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
                            ->forCreatedBy(auth()->id())
                            ->select('batch_code')
                            ->distinct()
                            ->orderByDesc('batch_code')
                            ->limit(50)
                            ->pluck('batch_code', 'batch_code')
                            ->toArray();
                    })
                    ->searchable(),

                Filter::make('order_date')
                    ->label('発注日')
                    ->form([
                        DatePicker::make('order_date')
                            ->label('発注日')
                            ->default(ClientSetting::systemDateYMD()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['order_date'], fn (Builder $q, $date) => $q->where('order_date', $date))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (! $data['order_date']) {
                            return null;
                        }

                        return '発注日: '.Carbon::parse($data['order_date'])->format('Y年m月d日');
                    }),

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
            ->recordActionsColumnLabel('操作')
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
                    ->requiresConfirmation()
                    ->modalHeading('FAX発注書生成')
                    ->modalDescription('通信欄に記載する内容を入力してください')
                    ->modalSubmitActionLabel('生成・ダウンロード')
                    ->schema([
                        Textarea::make('communication_notes')
                            ->label('通信欄')
                            ->rows(3)
                            ->maxLength(200),
                    ])
                    ->action(function (WmsOrderDataFile $record, array $data) {
                        try {
                            $notes = $data['communication_notes'] ?? null;

                            // 通信欄の内容を反映するため常にPDFを再生成
                            $pdfService = app(PurchaseOrderPdfService::class);
                            $pdfService->generateAndStore($record, $notes);
                            $record->refresh();

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
                    ->modalHeading('発注データをメールで送信します')
                    ->modalWidth('7xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn ($action) => $action->makeModalSubmitAction('submit', [])->label('送信')->color('danger'))
                    ->modalCancelActionLabel('送信せず閉じる')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('mail_to')
                                ->label('送信先メールアドレス')
                                ->email()
                                ->required()
                                ->default(function (WmsOrderDataFile $record) {
                                    $setting = WmsContractorSetting::where('contractor_id', $record->contractor_id)->first();

                                    return $record->mail_to ?? $setting?->order_mail ?? $record->contractor?->email ?? '';
                                }),

                            TextInput::make('mail_from_name')
                                ->label('送信名')
                                ->maxLength(100)
                                ->default(function (WmsOrderDataFile $record) {
                                    $setting = WmsContractorSetting::where('contractor_id', $record->contractor_id)->first();

                                    return $setting?->order_mail_from;
                                }),

                            TextInput::make('mail_title')
                                ->label('メールタイトル')
                                ->maxLength(200)
                                ->default(function (WmsOrderDataFile $record) {
                                    return self::replaceMailVariables(
                                        WmsContractorSetting::where('contractor_id', $record->contractor_id)->value('order_mail_title'),
                                        $record
                                    ) ?? '';
                                })
                                ->placeholder('未設定時: 【発注書】倉庫名 - 日付'),
                        ]),

                        Textarea::make('mail_content')
                            ->label('メール本文')
                            ->rows(12)
                            ->maxLength(5000)
                            ->default(function (WmsOrderDataFile $record) {
                                return self::replaceMailVariables(
                                    WmsContractorSetting::where('contractor_id', $record->contractor_id)->value('order_mail_content'),
                                    $record
                                ) ?? '';
                            })
                            ->placeholder('未設定時はシステムデフォルトを使用'),

                        Textarea::make('communication_notes')
                            ->label('通信欄（FAX発注書に記載）')
                            ->rows(3)
                            ->maxLength(200),

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

                            // FAX PDFを通信欄付きで再生成
                            if ($attachFax) {
                                $pdfService = app(PurchaseOrderPdfService::class);
                                $pdfService->generateAndStore($record, $data['communication_notes'] ?? null);
                                $record->refresh();
                            }

                            // メール送信（モーダルの値をオーバーライドとして渡す）
                            Mail::to($email)->send(new OrderDataMail(
                                dataFile: $record,
                                attachCsv: $attachCsv,
                                attachFax: $attachFax,
                                fromName: $data['mail_from_name'] ?? null,
                                subject: $data['mail_title'] ?? null,
                                content: $data['mail_content'] ?? null,
                            ));

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
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkSendMail')
                        ->label('メール一括送信')
                        ->icon('heroicon-o-envelope')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('選択した発注データをメールで一括送信')
                        ->modalDescription(function (Collection $records): string {
                            $total = $records->count();
                            $alreadySent = $records->filter(fn ($r) => $r->mail_sent_at !== null)->count();
                            $noMail = $records->filter(fn ($r) => ! $r->is_mail_order)->count();
                            $sendable = max(0, $total - $alreadySent - $noMail);

                            return "選択: {$total}件 → 送信対象: {$sendable}件"
                                .($alreadySent + $noMail > 0 ? "（スキップ: 送信済み{$alreadySent}件 / メール未設定{$noMail}件）" : '');
                        })
                        ->action(function (Collection $records) {
                            $sent = 0;
                            $skipped = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if ($record->mail_sent_at !== null) {
                                    $skipped++;

                                    continue;
                                }
                                if (! $record->is_mail_order) {
                                    $skipped++;

                                    continue;
                                }

                                try {
                                    $setting = WmsContractorSetting::where('contractor_id', $record->contractor_id)->first();
                                    $email = $record->mail_to ?? $setting?->order_mail ?? $record->contractor?->email;
                                    if (! $email) {
                                        $skipped++;

                                        continue;
                                    }

                                    $pdfService = app(PurchaseOrderPdfService::class);
                                    $pdfService->generateAndStore($record, null);
                                    $record->refresh();

                                    $subject = self::replaceMailVariables($setting?->order_mail_title, $record);
                                    $content = self::replaceMailVariables($setting?->order_mail_content, $record);

                                    Mail::to($email)->send(new OrderDataMail(
                                        dataFile: $record,
                                        attachCsv: true,
                                        attachFax: true,
                                        fromName: $setting?->order_mail_from,
                                        subject: $subject,
                                        content: $content,
                                    ));

                                    $record->markAsMailSent(auth()->id(), $email);
                                    $sent++;
                                } catch (\Exception $e) {
                                    $failed++;
                                }
                            }

                            Notification::make()
                                ->title('メール一括送信完了')
                                ->body("送信: {$sent}件 / スキップ: {$skipped}件 / 失敗: {$failed}件")
                                ->success()
                                ->send();
                        }),

                    BulkAction::make('bulkDownloadCsv')
                        ->label('CSV一括ダウンロード')
                        ->icon('heroicon-o-document-text')
                        ->color('primary')
                        ->action(function (Collection $records): StreamedResponse {
                            $service = app(OrderDataFileService::class);
                            $isFirst = true;
                            $csvContent = '';

                            foreach ($records as $record) {
                                if (! $record->file_path) {
                                    continue;
                                }

                                $content = Storage::disk('s3')->get($record->file_path);
                                if (! $content) {
                                    continue;
                                }

                                if ($isFirst) {
                                    $csvContent .= $content;
                                    $isFirst = false;
                                } else {
                                    $lines = explode("\n", $content);
                                    array_shift($lines);
                                    $remaining = implode("\n", $lines);
                                    if (trim($remaining) !== '') {
                                        $csvContent .= $remaining;
                                    }
                                }

                                $record->markAsCsvDownloaded(auth()->id());
                            }

                            $filename = 'order_data_bulk_'.now()->format('YmdHis').'.csv';

                            return response()->streamDownload(function () use ($csvContent) {
                                echo $csvContent;
                            }, $filename, [
                                'Content-Type' => 'text/csv',
                            ]);
                        }),

                    BulkAction::make('bulkDownloadFax')
                        ->label('FAX一括ダウンロード')
                        ->icon('heroicon-o-document')
                        ->color('success')
                        ->action(function (Collection $records): StreamedResponse {
                            $pdfService = app(PurchaseOrderPdfService::class);
                            $pdfBinary = $pdfService->generateBulk($records);

                            foreach ($records as $record) {
                                $record->markAsFaxDownloaded(auth()->id());
                            }

                            $filename = 'fax_bulk_'.now()->format('YmdHis').'.pdf';

                            return response()->streamDownload(function () use ($pdfBinary) {
                                echo $pdfBinary;
                            }, $filename, [
                                'Content-Type' => 'application/pdf',
                            ]);
                        }),
                ]),
            ])
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
