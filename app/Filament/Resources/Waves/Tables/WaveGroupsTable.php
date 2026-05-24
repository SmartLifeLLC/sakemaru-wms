<?php

namespace App\Filament\Resources\Waves\Tables;

use App\Enums\PaginationOptions;
use App\Filament\Concerns\HasExportAction;
use App\Filament\Resources\Waves\WaveResource;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\ClientPrinterDriver;
use App\Models\Sakemaru\Warehouse;
use App\Models\Wave;
use App\Models\WaveGroup;
use App\Models\WmsQueueProgress;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class WaveGroupsTable
{
    use HasExportAction;

    public static function configure(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->recordUrl(fn (WaveGroup $record): string => WaveResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('group_no')
                    ->label('生成グループ')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('shipping_date')
                    ->label('出荷日')
                    ->date('Y年m月d日')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('生成時刻')
                    ->dateTime('m/d H:i')
                    ->sortable(),

                TextColumn::make('time_slot')
                    ->label('時間帯')
                    ->badge()
                    ->state(fn (WaveGroup $record): string => ((int) $record->created_at?->format('H')) < 12 ? '午前' : '午後')
                    ->color(fn (string $state): string => $state === '午前' ? 'info' : 'warning'),

                TextColumn::make('document_types')
                    ->label('対象')
                    ->badge()
                    ->state(fn (WaveGroup $record): string => implode(' / ', static::targetDocumentTypeLabels($record))),

                TextColumn::make('warehouse.name')
                    ->label('倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('generation_type')
                    ->label('生成単位')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'buyer' => '得意先別',
                        default => '配送コース',
                    })
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('waves_count')
                    ->label('波動数')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('generation_result.earning_count')
                    ->label('営業出荷')
                    ->state(fn (WaveGroup $record): int => (int) ($record->generation_result['earning_count'] ?? 0))
                    ->suffix('件')
                    ->sortable(false),

                TextColumn::make('generation_result.stock_transfer_count')
                    ->label('物流出荷')
                    ->state(fn (WaveGroup $record): int => (int) ($record->generation_result['stock_transfer_count'] ?? 0))
                    ->suffix('件')
                    ->sortable(false),

                TextColumn::make('picking_lists')
                    ->label('ピッキングリスト')
                    ->badge()
                    ->state(fn (WaveGroup $record): string => empty($record->picking_lists) ? '未保存' : count($record->picking_lists).'件')
                    ->color(fn (string $state): string => $state === '未保存' ? 'gray' : 'success'),
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'], fn (Builder $q, $warehouseId) => $q->where('warehouse_id', $warehouseId))),

                Filter::make('shipping_date')
                    ->label('出荷日')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('shipping_date')
                            ->label('出荷日')
                            ->default(ClientSetting::systemDateYMD()),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['shipping_date'], fn (Builder $q, $date) => $q->where('shipping_date', $date))),

                SelectFilter::make('target_document_type')
                    ->label('対象')
                    ->options([
                        'shipment' => '営業出荷',
                        'transfer' => '物流出荷',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'], fn (Builder $q, string $type) => $q->whereJsonContains('target_document_types', $type))),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('詳細')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (WaveGroup $record): string => WaveResource::getUrl('view', ['record' => $record])),

                Action::make('waveGenerationProgress')
                    ->label('生成状況')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->modalHeading(fn (WaveGroup $record): string => "波動生成状況: {$record->group_no}")
                    ->modalWidth('4xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる')
                    ->modalContent(fn (WaveGroup $record): HtmlString => static::waveGenerationProgressHtml($record)),

                Action::make('downloadSavedPickingList')
                    ->label('ピッキングリスト出力')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('info')
                    ->visible(fn (WaveGroup $record): bool => ! empty($record->picking_lists))
                    ->modalHeading(fn (WaveGroup $record): string => "ピッキングリスト出力: {$record->group_no}")
                    ->modalDescription('リスト種別を選択し、保存済みの対象リストを出力します')
                    ->modalWidth('6xl')
                    ->extraModalWindowAttributes(['class' => 'picking-list-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn (Action $action) => $action->label('PDF出力')->color('gray'))
                    ->extraModalFooterActions(fn (Action $action): array => [
                        $action->makeModalSubmitAction('printerOutput', arguments: ['output' => 'printer'])
                            ->label('プリンター出力')
                            ->icon(Heroicon::OutlinedPrinter)
                            ->color('info'),
                    ])
                    ->modalCancelActionLabel('出力せず閉じる')
                    ->schema(fn (WaveGroup $record): array => [
                        ViewField::make('list_type')
                            ->label('リスト種別')
                            ->view('filament.forms.components.picking-list-type-select')
                            ->viewData([
                                'enabledTypes' => array_keys($record->picking_lists ?? []),
                            ])
                            ->required()
                            ->default(array_key_first($record->picking_lists ?? []))
                            ->live(),

                        ...static::printerSelectionSchema($record->warehouse_id),

                        Placeholder::make('wave_preview')
                            ->label('対象波動')
                            ->content(fn (WaveGroup $record): HtmlString => static::wavePreviewHtml($record)),
                    ])
                    ->action(fn (WaveGroup $record, array $data, array $arguments) => static::downloadSavedPickingList($record, $data, $arguments)),

                Action::make('cancelWaveGroup')
                    ->label('取消')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (WaveGroup $record): bool => $record->waves()->where('status', '!=', 'CLOSED')->exists())
                    ->modalHeading(fn (WaveGroup $record): string => "生成グループ取消: {$record->group_no}")
                    ->modalDescription('生成グループ配下の波動をまとめて取り消します。対象伝票はピッキング前に戻ります。')
                    ->modalWidth('3xl')
                    ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->modalSubmitAction(fn (Action $action) => $action->label('生成グループを取消')->color('danger'))
                    ->modalCancelActionLabel('取消せず閉じる')
                    ->modalContent(fn (WaveGroup $record): HtmlString => WavesTable::bulkCancelWaveModalContent(
                        $record->waves()->orderBy('id')->get()
                    ))
                    ->action(fn (WaveGroup $record) => static::cancelWaveGroup($record)),
            ], position: RecordActionsPosition::AfterColumns)
            ->toolbarActions([
                static::getExportAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function targetDocumentTypeLabels(WaveGroup $record): array
    {
        $types = $record->target_document_types ?: ['shipment'];

        return collect($types)
            ->map(fn (string $type): string => match ($type) {
                'transfer' => '物流出荷',
                default => '営業出荷',
            })
            ->unique()
            ->values()
            ->all();
    }

    public static function savedPickingListOptions(WaveGroup $record): array
    {
        $labels = [
            'primary' => '1次ピッキングリスト',
            'primary_total' => '1次ピッキングリスト(一括)',
            'shortage' => '欠品リスト',
            'secondary' => '2次ピッキングリスト',
            'secondary_v2' => '2次ピッキングリスト(V2)',
            'tertiary' => '3次ピッキングリスト',
        ];

        return collect($record->picking_lists ?? [])
            ->keys()
            ->mapWithKeys(fn (string $type): array => [$type => $labels[$type] ?? $type])
            ->toArray();
    }

    public static function downloadSavedPickingList(WaveGroup $record, array $data, array $arguments = [])
    {
        $listType = $data['list_type'] ?? null;
        $entry = $record->picking_lists[$listType] ?? null;

        if (! $entry || empty($entry['disk']) || empty($entry['path'])) {
            Notification::make()
                ->title('保存済みリストが見つかりません')
                ->warning()
                ->send();

            return null;
        }

        $disk = (string) $entry['disk'];
        $path = (string) $entry['path'];

        if (! Storage::disk($disk)->exists($path)) {
            Notification::make()
                ->title('S3上のファイルが見つかりません')
                ->body($path)
                ->danger()
                ->send();

            return null;
        }

        $filename = (string) ($entry['filename'] ?? basename($path));
        $mimeType = (string) ($entry['mime_type'] ?? 'application/pdf');
        $printerDriverId = ! empty($data['printer_driver_id']) ? (int) $data['printer_driver_id'] : null;
        $shouldPrint = ($arguments['output'] ?? 'pdf') === 'printer';

        if ($shouldPrint) {
            if (! $printerDriverId) {
                Notification::make()
                    ->title('プリンターを選択してください')
                    ->danger()
                    ->send();

                return null;
            }

            if ($disk !== 's3') {
                Notification::make()
                    ->title('プリンター出力できません')
                    ->body('S3上の保存済みPDFのみプリンター出力できます。')
                    ->danger()
                    ->send();

                return null;
            }

            $printer = ClientPrinterDriver::query()
                ->where('id', $printerDriverId)
                ->where('is_active', true)
                ->first();

            if (! $printer) {
                Notification::make()
                    ->title('プリンターが見つかりません')
                    ->danger()
                    ->send();

                return null;
            }

            $printerPath = static::copyPickingListForPrinterQueue($path, $record, $listType);
            if (! $printerPath) {
                return null;
            }

            DB::connection('sakemaru')
                ->table('document_picking_print_outputs')
                ->insert([
                    'client_id' => $printer->client_id,
                    'log_pdf_export_id' => null,
                    'creator_id' => auth()->id(),
                    'printer_driver_id' => $printerDriverId,
                    'file_path' => $printerPath,
                    'file_type' => 'S3',
                    'status' => 'STANDBY',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            Notification::make()
                ->title('プリンター出力を依頼しました')
                ->body($filename)
                ->success()
                ->send();

            return null;
        }

        return response()->streamDownload(
            fn () => print (Storage::disk($disk)->get($path)),
            $filename,
            ['Content-Type' => $mimeType]
        );
    }

    private static function copyPickingListForPrinterQueue(string $path, WaveGroup $record, string $listType): ?string
    {
        $safeListType = preg_replace('/[^A-Za-z0-9_-]/', '_', $listType) ?: 'list';
        $printerPath = sprintf(
            'data/pdf/%s/wms_pick_%d_%s_%s.pdf',
            now()->format('Ymd'),
            $record->id,
            $safeListType,
            now()->format('YmdHis')
        );

        $stream = null;

        try {
            $disk = Storage::disk('s3');
            $stream = $disk->readStream($path);

            if ($stream === false) {
                Log::error('Failed to read WMS picking list for printer output', [
                    'wave_group_id' => $record->id,
                    'source_path' => $path,
                    'printer_path' => $printerPath,
                ]);

                Notification::make()
                    ->title('プリンター出力用PDFの準備に失敗しました')
                    ->danger()
                    ->send();

                return null;
            }

            if (! $disk->put($printerPath, $stream)) {
                Notification::make()
                    ->title('プリンター出力用PDFの準備に失敗しました')
                    ->danger()
                    ->send();

                return null;
            }
        } catch (\Throwable $e) {
            Log::error('Failed to copy WMS picking list for printer output', [
                'wave_group_id' => $record->id,
                'source_path' => $path,
                'printer_path' => $printerPath,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('プリンター出力用PDFの準備に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $printerPath;
    }

    public static function printerSelectionSchema(?int $warehouseId = null): array
    {
        return [
            Select::make('printer_warehouse_id')
                ->label('プリンタ倉庫')
                ->options(
                    Warehouse::query()
                        ->where('is_active', true)
                        ->pluck('name', 'id')
                )
                ->default($warehouseId)
                ->searchable()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('printer_driver_id', null)),
            Select::make('printer_driver_id')
                ->label('プリンター')
                ->options(function (Get $get) {
                    $selectedWarehouseId = $get('printer_warehouse_id');
                    if (! $selectedWarehouseId) {
                        return [];
                    }

                    $printers = ClientPrinterDriver::query()
                        ->where('warehouse_id', $selectedWarehouseId)
                        ->where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn ($printer) => [
                            $printer->id => filled($printer->user_name) ? $printer->user_name : $printer->display_name,
                        ]);

                    return ['' => '選択なし（PDFのみ出力）'] + $printers->toArray();
                })
                ->default('')
                ->live()
                ->searchable()
                ->helperText('PDF出力はダウンロード、プリンター出力は選択したプリンターに送信します。'),
        ];
    }

    public static function wavePreviewHtml(WaveGroup $record): HtmlString
    {
        $record->loadMissing('waves.waveSetting.deliveryCourse');
        $waves = $record->waves->sortBy('wave_no')->values();

        if ($waves->isEmpty()) {
            return new HtmlString('<div class="flex flex-col items-center justify-center py-8 text-slate-400 dark:text-gray-500"><i class="fa fa-file-alt text-2xl mb-2"></i><p class="text-sm">対象波動がありません</p></div>');
        }

        $statusLabels = [
            'PENDING' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">未出荷</span>',
            'PICKING' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">ピッキング中</span>',
            'SHORTAGE' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400">欠品あり</span>',
            'COMPLETED' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400">出荷完了</span>',
            'CLOSED' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">クローズ</span>',
        ];

        $html = '<div class="space-y-3">';
        $html .= '<div class="overflow-hidden rounded-lg border border-slate-200 dark:border-gray-700">';
        $html .= '<div class="max-h-60 overflow-y-auto">';
        $html .= '<table class="w-full text-sm">';
        $html .= '<thead class="bg-slate-50 dark:bg-gray-900 sticky top-0 z-10"><tr>';
        $html .= '<th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">波動番号</th>';
        $html .= '<th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">コース名</th>';
        $html .= '<th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">状況</th>';
        $html .= '</tr></thead><tbody class="divide-y divide-slate-200 dark:divide-gray-700">';

        foreach ($waves as $wave) {
            $waveNo = e((string) $wave->wave_no);
            $courseName = e((string) ($wave->waveSetting?->deliveryCourse?->name ?? '-'));
            $status = $statusLabels[$wave->status] ?? e((string) $wave->status);
            $html .= '<tr class="hover:bg-slate-50 dark:hover:bg-gray-700">';
            $html .= "<td class=\"px-3 py-2 text-slate-700 dark:text-gray-300 font-mono text-xs\">{$waveNo}</td>";
            $html .= "<td class=\"px-3 py-2 text-slate-700 dark:text-gray-300\">{$courseName}</td>";
            $html .= "<td class=\"px-3 py-2\">{$status}</td>";
            $html .= '</tr>';
        }

        $count = $waves->count();
        $html .= '</tbody></table></div></div>';
        $html .= '<div class="flex justify-between items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">';
        $html .= '<span class="text-sm font-bold text-slate-700 dark:text-gray-200">合計</span>';
        $html .= "<span class=\"text-lg font-bold text-blue-600 dark:text-blue-400\">{$count} 波動</span>";
        $html .= '</div></div>';

        return new HtmlString($html);
    }

    public static function cancelWaveGroup(WaveGroup $record): void
    {
        try {
            $waveIds = $record->waves()
                ->where('status', '!=', 'CLOSED')
                ->pluck('id')
                ->values()
                ->all();

            if (empty($waveIds)) {
                Notification::make()
                    ->title('取消対象の波動がありません')
                    ->warning()
                    ->send();

                return;
            }

            $cancelledWaveNos = [];

            DB::connection('sakemaru')->transaction(function () use ($record, $waveIds, &$cancelledWaveNos) {
                $lockedWaves = Wave::query()
                    ->whereIn('id', $waveIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($lockedWaves as $wave) {
                    WavesTable::cancelWaveInTransaction($wave);
                    $cancelledWaveNos[] = $wave->wave_no;
                }

                $record->update([
                    'cancelled_at' => now(),
                    'cancelled_by' => auth()->id(),
                    'cancel_reason' => '生成グループ画面から取消',
                ]);
            });

            Notification::make()
                ->title('生成グループを取消しました')
                ->body(count($cancelledWaveNos).'件の波動をクローズし、対象伝票をピッキング前に戻しました。')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('生成グループ取消に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function waveGenerationProgressHtml(WaveGroup $record): HtmlString
    {
        $jobs = WmsQueueProgress::query()
            ->where('job_type', WmsQueueProgress::JOB_TYPE_WAVE_GENERATION)
            ->where('metadata->wave_group_id', $record->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($jobs->isEmpty()) {
            return new HtmlString('<div class="py-8 text-center text-sm text-slate-500 dark:text-gray-400">この生成グループの波動生成ジョブはまだありません。</div>');
        }

        $statusClasses = [
            'pending' => 'bg-slate-100 text-slate-700 dark:bg-gray-700 dark:text-gray-200',
            'processing' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
            'completed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
            'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
        ];

        $statusLabels = [
            'pending' => '待機中',
            'processing' => '処理中',
            'completed' => '完了',
            'failed' => '失敗',
        ];

        $html = '<div class="space-y-3">';
        foreach ($jobs as $job) {
            $result = $job->result ?? [];
            $status = $job->status?->value ?? (string) $job->status;
            $statusClass = $statusClasses[$status] ?? $statusClasses['pending'];
            $statusLabel = $statusLabels[$status] ?? $status;
            $progress = (int) $job->progress;
            $message = e($job->message ?? '');
            $waveCount = isset($result['wave_ids']) && is_array($result['wave_ids']) ? count($result['wave_ids']) : null;
            $timing = $result['timings_ms']['total'] ?? null;
            $createdAt = $job->created_at?->format('m/d H:i:s') ?? '-';
            $startedAt = $job->started_at?->format('m/d H:i:s') ?? '-';
            $completedAt = $job->completed_at?->format('m/d H:i:s') ?? '-';

            $html .= '<div class="rounded-lg border border-slate-200 p-3 dark:border-gray-700">';
            $html .= '<div class="flex items-center justify-between gap-3">';
            $html .= '<div class="font-mono text-xs text-slate-700 dark:text-gray-200">'.e($record->group_no).'</div>';
            $html .= "<span class=\"inline-flex rounded px-2 py-0.5 text-xs font-medium {$statusClass}\">{$statusLabel}</span>";
            $html .= '</div>';
            $html .= '<div class="mt-2 h-2 overflow-hidden rounded bg-slate-100 dark:bg-gray-800">';
            $html .= "<div class=\"h-full bg-blue-500\" style=\"width: {$progress}%\"></div>";
            $html .= '</div>';
            $html .= "<div class=\"mt-2 text-xs text-slate-600 dark:text-gray-300\">{$message}</div>";
            $html .= '<div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-gray-400">';
            $html .= "<span>作成: {$createdAt}</span>";
            $html .= "<span>開始: {$startedAt}</span>";
            $html .= "<span>完了: {$completedAt}</span>";
            $html .= "<span>進捗: {$progress}%</span>";
            if ($waveCount !== null) {
                $html .= "<span>波動: {$waveCount}件</span>";
            }
            if ($timing !== null) {
                $html .= '<span>所要: '.number_format(((int) $timing) / 1000, 1).'秒</span>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';

        return new HtmlString($html);
    }
}
