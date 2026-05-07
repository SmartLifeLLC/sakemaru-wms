<?php

namespace App\Filament\Resources\WmsOrderDocuments\Tables;

use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderTransmissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class WmsOrderDocumentsTable
{
    use HasExportAction;

    /**
     * メッセージIDでXMLファイルを検索（S3）
     */
    public static function findXmlFileByMessageId(string $messageId): ?string
    {
        $baseDir = 'jx-client/requests';

        // S3から全ファイルを取得してメッセージIDで検索
        $allFiles = Storage::disk('s3')->allFiles($baseDir);

        foreach ($allFiles as $file) {
            // ファイル名にメッセージIDが含まれているか確認
            if (str_contains(basename($file), $messageId)) {
                return $file;
            }
        }

        return null;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('batch_code')
                    ->label('実行CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('ステータス')
                    ->badge()
                    ->formatStateUsing(fn (TransmissionDocumentStatus $state): string => $state->getLabel())
                    ->color(fn (TransmissionDocumentStatus $state): string => $state->color())
                    ->sortable(),

                TextColumn::make('order_date')
                    ->label('発注日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('expected_arrival_date')
                    ->label('入荷日')
                    ->date('m/d')
                    ->sortable(),

                TextColumn::make('contractor.name')
                    ->label('発注先')
                    ->state(fn ($record) => $record->contractor
                        ? "[{$record->contractor->code}]{$record->contractor->name}"
                        : '-')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

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
                    ->label('DAT')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->action(function (WmsOrderJxDocument $record) {
                        if (! $record->file_path) {
                            Notification::make()->title('ファイルが見つかりません')->danger()->send();

                            return;
                        }

                        $url = app(OrderTransmissionService::class)->getDownloadUrl($record);
                        if (! $url) {
                            Notification::make()->title('ダウンロードURLの生成に失敗しました')->danger()->send();

                            return;
                        }

                        return redirect($url);
                    }),

                Action::make('downloadCsv')
                    ->label('CSV')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->visible(fn ($record) => ! empty($record->csv_path))
                    ->action(function (WmsOrderJxDocument $record) {
                        $url = Storage::disk('s3')->temporaryUrl($record->csv_path, now()->addHour());

                        return $url ? redirect($url) : Notification::make()->title('CSVファイルが見つかりません')->danger()->send();
                    }),

                Action::make('downloadXml')
                    ->label('XML')
                    ->icon('heroicon-o-code-bracket')
                    ->color('warning')
                    ->visible(fn ($record) => ! empty($record->jx_message_id))
                    ->action(function (WmsOrderJxDocument $record) {
                        $xmlPath = self::findXmlFileByMessageId($record->jx_message_id);

                        if (! $xmlPath) {
                            Notification::make()->title('送信XMLが見つかりません')->danger()->send();

                            return;
                        }

                        return redirect(route('jx-xml-files.download', ['path' => $xmlPath]));
                    }),

                Action::make('delete')
                    ->label('削除')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn ($record) => in_array($record->status, [
                        TransmissionDocumentStatus::DRAFT,
                        TransmissionDocumentStatus::TEST,
                    ]))
                    ->requiresConfirmation()
                    ->action(function (WmsOrderJxDocument $record) {
                        if ($record->file_path && Storage::disk('s3')->exists($record->file_path)) {
                            Storage::disk('s3')->delete($record->file_path);
                        }
                        $record->delete();
                        Notification::make()->title('削除しました')->success()->send();
                    }),
            ])
            ->toolbarActions([
                Action::make('transmitJx')
                    ->label('JX送信')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->modalHeading('JX-FINET送信')
                    ->modalDescription('送信前のJXファイルを送信します')
                    ->modalSubmitActionLabel('送信')
                    ->modalCancelActionLabel('送信せず閉じる')
                    ->schema([
                        Select::make('contractor_id')
                            ->label('送信先')
                            ->options(function () {
                                return static::jxTransmitTargetOptions();
                            })
                            ->required()
                            ->helperText('送信前のJXファイルがある送信先のみ表示されます'),
                    ])
                    ->action(function (array $data) {
                        $contractorId = (int) $data['contractor_id'];

                        $service = app(OrderTransmissionService::class);
                        $result = $service->transmitPendingDocumentsForContractor([$contractorId]);

                        if (! empty($result['transmitted'])) {
                            $count = $result['order_count'] ?? count($result['transmitted']);
                            Notification::make()
                                ->title("JX送信完了（{$count}件）")
                                ->success()
                                ->send();
                        }

                        if (! empty($result['errors'])) {
                            $errorMessages = collect($result['errors'])
                                ->map(fn ($e) => $e['error'])
                                ->implode("\n");
                            Notification::make()
                                ->title('送信エラー')
                                ->body($errorMessages)
                                ->danger()
                                ->send();
                        }

                        if (empty($result['transmitted']) && empty($result['errors'])) {
                            Notification::make()
                                ->title('送信対象がありません')
                                ->warning()
                                ->send();
                        }
                    }),

                Action::make('downloadCorrectionPreviewCsv')
                    ->label('修正CSV生成')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->modalHeading('修正再送CSV生成')
                    ->modalDescription('送信済みの当日発注確定分から、再送前確認用CSVを生成します。')
                    ->modalSubmitActionLabel('CSV生成')
                    ->modalCancelActionLabel('生成せず閉じる')
                    ->schema(static::correctionResendSchema())
                    ->action(function (array $data) {
                        try {
                            $preview = app(OrderTransmissionService::class)->buildCorrectionResendPreviewCsv(
                                (int) $data['contractor_id'],
                                $data['transmitted_date']
                            );

                            return response()->streamDownload(
                                fn () => print $preview['content'],
                                $preview['filename'],
                                ['Content-Type' => 'text/csv; charset=UTF-8']
                            );
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('CSV生成に失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('generateCorrectionResendJx')
                    ->label('修正JX生成')
                    ->icon('heroicon-o-document-plus')
                    ->color('danger')
                    ->modalHeading('修正再送JX生成')
                    ->modalDescription('送信済みの当日発注確定分を1つのJXファイルにまとめて生成します。生成後、ツールバーの「JX送信」から送信してください。')
                    ->modalSubmitActionLabel('JXファイル生成')
                    ->modalCancelActionLabel('生成せず閉じる')
                    ->schema(static::correctionResendSchema())
                    ->action(function (array $data) {
                        $result = app(OrderTransmissionService::class)->generateCorrectionResendFiles(
                            (int) $data['contractor_id'],
                            $data['transmitted_date']
                        );

                        if ($result['success']) {
                            $documentIds = collect($result['files'])
                                ->pluck('document_id')
                                ->filter()
                                ->implode(', ');

                            Notification::make()
                                ->title('修正JXファイルを生成しました')
                                ->body("発注数: {$result['total_orders']} / 伝票ID: {$documentIds}")
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('修正JX生成に失敗しました')
                            ->body(implode("\n", $result['errors'] ?? ['生成失敗']))
                            ->danger()
                            ->send();
                    }),

                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * 修正再送アクション共通フォーム。
     */
    private static function correctionResendSchema(): array
    {
        return [
            Select::make('contractor_id')
                ->label('仕入先')
                ->options(fn () => static::jxContractorOptions())
                ->searchable()
                ->required(),

            DatePicker::make('transmitted_date')
                ->label('送信日')
                ->default(now())
                ->required(),
        ];
    }

    /**
     * JX送信先の仕入先選択肢。
     */
    private static function jxContractorOptions(): array
    {
        return WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET)
            ->with('contractor')
            ->get()
            ->filter(fn (WmsContractorSetting $setting) => $setting->contractor !== null)
            ->mapWithKeys(fn (WmsContractorSetting $setting) => [
                $setting->contractor_id => "[{$setting->contractor->code}]{$setting->contractor->name}",
            ])
            ->all();
    }

    /**
     * JX送信対象（送信前ドキュメントがある送信先）の選択肢。
     */
    private static function jxTransmitTargetOptions(): array
    {
        $jxContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET)
            ->pluck('contractor_id')
            ->toArray();

        if (empty($jxContractorIds)) {
            return [];
        }

        $documents = WmsOrderJxDocument::query()
            ->where('status', TransmissionDocumentStatus::PENDING)
            ->whereIn('contractor_id', $jxContractorIds)
            ->with('contractor')
            ->get();

        if ($documents->isEmpty()) {
            return [];
        }

        return $documents
            ->groupBy('contractor_id')
            ->mapWithKeys(function ($items, $contractorId) {
                $contractor = $items->first()->contractor;
                $documentCount = $items->count();
                $orderCount = $items->sum('order_count');

                $suffix = $orderCount > 0
                    ? "{$documentCount}件 / {$orderCount}品"
                    : "{$documentCount}件";

                $label = $contractor
                    ? "[{$contractor->code}]{$contractor->name} ({$suffix})"
                    : "ID:{$contractorId} ({$suffix})";

                return [$contractorId => $label];
            })
            ->all();
    }
}
