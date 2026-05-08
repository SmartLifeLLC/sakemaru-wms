<?php

namespace App\Filament\Resources\WmsOrderDocuments\Tables;

use App\Enums\AutoOrder\CandidateStatus;
use App\Enums\AutoOrder\OrderDataFileStatus;
use App\Enums\AutoOrder\TransmissionDocumentStatus;
use App\Enums\AutoOrder\TransmissionType;
use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderDataFile;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoOrder\PurchaseOrderPdfService;
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

                Action::make('retransmit')
                    ->label('再送信')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === TransmissionDocumentStatus::TRANSMITTED)
                    ->requiresConfirmation()
                    ->modalHeading('JXファイルを再生成して再送信')
                    ->modalDescription('送信済みの発注データから新しいJXファイルを作成して送信します。')
                    ->modalSubmitActionLabel('新規作成して再送信')
                    ->modalCancelActionLabel('送信せず閉じる')
                    ->action(function (WmsOrderJxDocument $record) {
                        $result = app(OrderTransmissionService::class)->retransmitDocumentById($record->id);

                        if ($result['success']) {
                            Notification::make()
                                ->title('再送信しました')
                                ->body('新規伝票ID: '.($result['document_id'] ?? '-').' / message_id: '.($result['message_id'] ?? '-'))
                                ->success()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('再送信に失敗しました')
                            ->body($result['error'] ?? '送信失敗')
                            ->danger()
                            ->send();
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
                Action::make('downloadSampleJx')
                    ->label('サンプルJX')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->modalHeading('サンプルJXファイルダウンロード')
                    ->modalDescription('選択した仕入先の既存発注候補からサンプルDATを生成します。送信・DB保存・S3保存は行いません。')
                    ->modalSubmitActionLabel('DATダウンロード')
                    ->modalCancelActionLabel('生成せず閉じる')
                    ->schema(static::sampleDownloadSchema())
                    ->action(function (array $data) {
                        try {
                            $sample = static::buildSampleJxFile((int) $data['contractor_id']);

                            return response()->streamDownload(
                                fn () => print $sample['content'],
                                $sample['filename'],
                                ['Content-Type' => 'application/octet-stream']
                            );
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('サンプルJX生成に失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('downloadSampleFax')
                    ->label('サンプルFAX')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('gray')
                    ->modalHeading('サンプルFAXダウンロード')
                    ->modalDescription('選択した仕入先の既存発注候補からFAX発注書PDFを生成します。送信・DB保存・S3保存は行いません。')
                    ->modalSubmitActionLabel('PDFダウンロード')
                    ->modalCancelActionLabel('生成せず閉じる')
                    ->schema(static::sampleDownloadSchema())
                    ->action(function (array $data) {
                        try {
                            $sample = static::buildSampleFaxFile((int) $data['contractor_id']);

                            return response()->streamDownload(
                                fn () => print $sample['content'],
                                $sample['filename'],
                                ['Content-Type' => 'application/pdf']
                            );
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('サンプルFAX生成に失敗しました')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('transmitJx')
                    ->label('JX送信')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->modalHeading('JX-FINET送信')
                    ->modalDescription('送信前のJXファイル、または発注確定済みデータを送信します')
                    ->modalSubmitActionLabel('送信')
                    ->modalCancelActionLabel('送信せず閉じる')
                    ->schema([
                        Select::make('contractor_id')
                            ->label('送信先')
                            ->options(function () {
                                return static::jxTransmitTargetOptions();
                            })
                            ->required()
                            ->helperText('送信前のJXファイル、または発注確定済み未送信データがある送信先のみ表示されます'),
                    ])
                    ->action(function (array $data) {
                        $contractorId = (int) $data['contractor_id'];

                        $service = app(OrderTransmissionService::class);
                        $result = $service->transmitPendingOrGenerateForContractor($contractorId);

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
     * サンプルダウンロード共通フォーム。
     */
    private static function sampleDownloadSchema(): array
    {
        return [
            Select::make('contractor_id')
                ->label('仕入先')
                ->options(fn () => static::jxContractorOptions())
                ->searchable()
                ->required()
                ->helperText('選択した仕入先、またはその集約元仕入先の既存発注候補からサンプルを作成します。'),
        ];
    }

    /**
     * サンプルJXファイルを生成する。DB/S3には保存しない。
     *
     * @return array{content: string, filename: string}
     */
    private static function buildSampleJxFile(int $contractorId): array
    {
        $candidates = static::sampleCandidatesForContractor($contractorId, 30);
        if ($candidates->isEmpty()) {
            throw new \RuntimeException('サンプル生成に使える発注候補がありません');
        }

        $generator = app(OrderTransmissionService::class)->getGenerator();
        if (! $generator) {
            throw new \RuntimeException('JXファイル生成設定がありません');
        }

        $files = collect($generator->generate($candidates));
        $file = $files->firstWhere('contractor_id', $contractorId) ?? $files->first();
        if (! $file) {
            throw new \RuntimeException('発注コード未設定などによりサンプルJXを生成できませんでした');
        }

        return [
            'content' => $file['content'],
            'filename' => 'sample_'.$file['filename'],
        ];
    }

    /**
     * サンプルFAX PDFを生成する。DB/S3には保存しない。
     *
     * @return array{content: string, filename: string}
     */
    private static function buildSampleFaxFile(int $contractorId): array
    {
        $candidates = static::sampleCandidatesForContractor($contractorId, 50);
        if ($candidates->isEmpty()) {
            throw new \RuntimeException('サンプル生成に使える発注候補がありません');
        }

        $faxCandidates = $candidates
            ->groupBy(fn (WmsOrderCandidate $candidate): string => "{$candidate->warehouse_id}:{$candidate->contractor_id}")
            ->sortByDesc(fn ($group): int => $group->count())
            ->first()
            ?->values();

        if (! $faxCandidates || $faxCandidates->isEmpty()) {
            throw new \RuntimeException('サンプルFAXを生成できる発注候補がありません');
        }

        /** @var WmsOrderCandidate $first */
        $first = $faxCandidates->first();
        $dataFile = new WmsOrderDataFile([
            'batch_code' => 'SAMPLE'.now()->format('YmdHis'),
            'warehouse_id' => $first->warehouse_id,
            'contractor_id' => $first->contractor_id,
            'order_date' => now(),
            'expected_arrival_date' => $first->expected_arrival_date ?? now()->addDay(),
            'order_count' => $faxCandidates->count(),
            'total_quantity' => $faxCandidates->sum('order_quantity'),
            'status' => OrderDataFileStatus::GENERATED,
            'is_test' => true,
        ]);
        $dataFile->setRelation('warehouse', $first->warehouse);
        $dataFile->setRelation('contractor', $first->contractor);

        $content = app(PurchaseOrderPdfService::class)->generate(
            $faxCandidates,
            $dataFile,
            'サンプルFAXです。実発注には使用しないでください。'
        );

        $contractorCode = $first->contractor?->code ?? $first->contractor_id;

        return [
            'content' => $content,
            'filename' => "sample_fax_{$contractorCode}_".now()->format('YmdHis').'.pdf',
        ];
    }

    private static function sampleCandidatesForContractor(int $contractorId, int $limit)
    {
        return WmsOrderCandidate::query()
            ->whereIn('contractor_id', static::sampleSourceContractorIds($contractorId))
            ->whereIn('status', [
                CandidateStatus::CONFIRMED->value,
                CandidateStatus::APPROVED->value,
                CandidateStatus::PENDING->value,
            ])
            ->whereNotNull('ordering_code')
            ->with(['warehouse', 'item', 'contractor'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private static function sampleSourceContractorIds(int $contractorId): array
    {
        $contractorIds = [$contractorId];

        $generator = app(OrderTransmissionService::class)->getGenerator();
        foreach ($generator?->getTransmissionContractorMapping() ?? [] as $sourceId => $targetId) {
            if ((int) $targetId === $contractorId) {
                $contractorIds[] = (int) $sourceId;
            }
        }

        $settingSourceIds = WmsContractorSetting::query()
            ->where('transmission_contractor_id', $contractorId)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        return array_values(array_unique(array_merge($contractorIds, $settingSourceIds)));
    }

    /**
     * JX送信先の仕入先選択肢。
     */
    private static function jxContractorOptions(): array
    {
        $contractorIds = static::jxTransmissionContractorIds();

        return Contractor::query()
            ->whereIn('id', $contractorIds)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Contractor $contractor) => [
                $contractor->id => "[{$contractor->code}]{$contractor->name}",
            ])
            ->all();
    }

    private static function jxTransmitTargetOptions(): array
    {
        return static::jxContractorOptions();
    }

    private static function jxTransmissionContractorIds(): array
    {
        $settingContractorIds = WmsContractorSetting::query()
            ->where('transmission_type', TransmissionType::JX_FINET)
            ->pluck('contractor_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        $generator = app(OrderTransmissionService::class)->getGenerator();
        $generatorContractorIds = $generator?->getJxTransmissionContractorIds() ?? [];
        $mapping = $generator?->getTransmissionContractorMapping() ?? [];

        return array_values(array_unique(array_merge(
            $settingContractorIds,
            array_map('intval', $generatorContractorIds),
            array_map('intval', array_values($mapping))
        )));
    }
}
