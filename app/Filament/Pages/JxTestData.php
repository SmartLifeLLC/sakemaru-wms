<?php

namespace App\Filament\Pages;

use App\Enums\EMenu;
use App\Models\WmsJxTransmissionLog;
use App\Models\WmsOrderJxSetting;
use App\Services\JX\JxTestFileGenerator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class JxTestData extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected string $view = 'filament.pages.jx-test-data';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::JX_TEST_DATA->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::JX_TEST_DATA->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::JX_TEST_DATA->sort();
    }

    public static function canAccess(): bool
    {
        // Only show in non-production environments
        return app()->environment(['local', 'development', 'staging']);
    }

    public function getTitle(): string|HtmlString
    {
        return new HtmlString(
            'JXテストデータ <span class="ml-2 inline-flex items-center rounded-md bg-warning-50 dark:bg-warning-400/10 px-2 py-1 text-xs font-medium text-warning-700 dark:text-warning-400 ring-1 ring-inset ring-warning-600/20 dark:ring-warning-400/20">テスト環境のみ</span>'
        );
    }

    /**
     * アクション名の一覧
     */
    public function getActionNames(): array
    {
        return [
            'showHelp',
            'generateEmptyFile',
            'generateFullOrderFile',
            'generateAggregatedFile',
            'generateAllPatterns',
        ];
    }

    /**
     * ヘルプ表示アクション
     */
    public function showHelpAction(): Action
    {
        return Action::make('showHelp')
            ->label('説明')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->modalHeading('JX送信テストの流れ')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('閉じる')
            ->modalContent(view('filament.pages.partials.jx-test-help'));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * 空ファイル生成アクション
     */
    public function generateEmptyFileAction(): Action
    {
        return Action::make('generateEmptyFile')
            ->label('空ファイル生成')
            ->icon('heroicon-o-document')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('空ファイルを生成')
            ->modalDescription('発注データなしの空のJXファイルを生成します。JX送信プロトコルのテストに使用します。')
            ->form([
                Select::make('jx_setting_id')
                    ->label('JX設定')
                    ->options(fn () => WmsOrderJxSetting::where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => "[{$s->id}] {$s->name}"])
                    )
                    ->required()
                    ->searchable(),

                Toggle::make('transmit')
                    ->label('生成後にJX送信を実行')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                set_time_limit(0);

                try {
                    $generator = app(JxTestFileGenerator::class);
                    $result = $generator->generateEmptyFile($data['jx_setting_id']);

                    $message = "ファイル: {$result['filename']}\n";
                    $message .= "サイズ: {$result['file_size']} bytes\n";
                    $message .= "レコード数: {$result['record_count']}";

                    if ($data['transmit']) {
                        $transmitResult = $generator->transmitFile($data['jx_setting_id'], $result['content']);
                        if ($transmitResult->succeeded()) {
                            $message .= "\n\n送信成功: {$transmitResult->messageId}";
                        } else {
                            $message .= "\n\n送信失敗: {$transmitResult->errorMessage}";
                        }
                    }

                    Notification::make()
                        ->title('空ファイルを生成しました')
                        ->body($message)
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * 全商品ファイル生成アクション
     */
    public function generateFullOrderFileAction(): Action
    {
        return Action::make('generateFullOrderFile')
            ->label('全商品ファイル生成')
            ->icon('heroicon-o-document-text')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('全商品ファイルを生成')
            ->modalDescription('JX設定に紐づく発注先の全商品を含む発注ファイルを生成します。')
            ->form([
                Select::make('jx_setting_id')
                    ->label('JX設定')
                    ->options(fn () => WmsOrderJxSetting::where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => "[{$s->id}] {$s->name}"])
                    )
                    ->required()
                    ->searchable(),

                TextInput::make('max_items')
                    ->label('最大商品数')
                    ->helperText('タイムアウト防止のため100以下を推奨。全商品はArtisanコマンドを使用してください。')
                    ->numeric()
                    ->default(50)
                    ->required()
                    ->minValue(1)
                    ->maxValue(200),

                Toggle::make('transmit')
                    ->label('生成後にJX送信を実行')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                set_time_limit(0);

                try {
                    $generator = app(JxTestFileGenerator::class);
                    $maxItems = (int) $data['max_items'];

                    $result = $generator->generateFullOrderFile(
                        $data['jx_setting_id'],
                        null,
                        $maxItems
                    );

                    $message = "ファイル: {$result['filename']}\n";
                    $message .= "サイズ: {$result['file_size']} bytes\n";
                    $message .= "レコード数: {$result['record_count']}\n";
                    $message .= "発注数: {$result['order_count']}";

                    if (isset($result['contractors'])) {
                        $message .= "\n発注先: ".implode(', ', $result['contractors']);
                    }

                    if ($data['transmit']) {
                        $transmitResult = $generator->transmitFile($data['jx_setting_id'], $result['content']);
                        if ($transmitResult->succeeded()) {
                            $message .= "\n\n送信成功: {$transmitResult->messageId}";
                        } else {
                            $message .= "\n\n送信失敗: {$transmitResult->errorMessage}";
                        }
                    }

                    Notification::make()
                        ->title('全商品ファイルを生成しました')
                        ->body($message)
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * 集約テストファイル生成アクション
     */
    public function generateAggregatedFileAction(): Action
    {
        return Action::make('generateAggregatedFile')
            ->label('集約テストファイル生成')
            ->icon('heroicon-o-document-duplicate')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('集約テストファイルを生成')
            ->modalDescription('複数発注先のデータを1ファイルに集約したテストファイルを生成します（カナカンケース向け）。')
            ->form([
                Select::make('jx_setting_id')
                    ->label('JX設定')
                    ->options(fn () => WmsOrderJxSetting::where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => "[{$s->id}] {$s->name}"])
                    )
                    ->required()
                    ->searchable()
                    ->default(1),

                TextInput::make('max_items_per_contractor')
                    ->label('発注先ごとの最大商品数')
                    ->helperText('タイムアウト防止のため20以下を推奨。全商品はArtisanコマンドを使用してください。')
                    ->numeric()
                    ->default(10)
                    ->required()
                    ->minValue(1)
                    ->maxValue(50),

                Toggle::make('transmit')
                    ->label('生成後にJX送信を実行')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                set_time_limit(0);

                try {
                    $generator = app(JxTestFileGenerator::class);
                    $maxItems = (int) $data['max_items_per_contractor'];

                    $result = $generator->generateAggregatedFile(
                        $data['jx_setting_id'],
                        null,
                        $maxItems
                    );

                    $message = "ファイル: {$result['filename']}\n";
                    $message .= "サイズ: {$result['file_size']} bytes\n";
                    $message .= "レコード数: {$result['record_count']}\n";
                    $message .= "発注数: {$result['order_count']}";

                    if (isset($result['contractors'])) {
                        $message .= "\n集約発注先: ".implode(', ', $result['contractors']);
                        $message .= " ({$result['aggregated_from']}社)";
                    }

                    if ($data['transmit']) {
                        $transmitResult = $generator->transmitFile($data['jx_setting_id'], $result['content']);
                        if ($transmitResult->succeeded()) {
                            $message .= "\n\n送信成功: {$transmitResult->messageId}";
                        } else {
                            $message .= "\n\n送信失敗: {$transmitResult->errorMessage}";
                        }
                    }

                    Notification::make()
                        ->title('集約テストファイルを生成しました')
                        ->body($message)
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * 全パターン一括生成アクション
     */
    public function generateAllPatternsAction(): Action
    {
        return Action::make('generateAllPatterns')
            ->label('全パターン一括生成')
            ->icon('heroicon-o-rectangle-stack')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('全パターンを一括生成')
            ->modalDescription('選択したJX設定に対して、空ファイル・全商品ファイル・集約テストファイルの3パターンを一括生成します。')
            ->form([
                Select::make('jx_setting_id')
                    ->label('JX設定')
                    ->options(fn () => WmsOrderJxSetting::where('is_active', true)
                        ->get()
                        ->mapWithKeys(fn ($s) => [$s->id => "[{$s->id}] {$s->name}"])
                    )
                    ->required()
                    ->searchable(),

                TextInput::make('max_items')
                    ->label('最大商品数')
                    ->helperText('タイムアウト防止のため30以下を推奨')
                    ->numeric()
                    ->default(20)
                    ->required()
                    ->minValue(1)
                    ->maxValue(100),

                Toggle::make('transmit')
                    ->label('生成後にJX送信を実行')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                set_time_limit(0);

                try {
                    $generator = app(JxTestFileGenerator::class);
                    $jxSettingId = $data['jx_setting_id'];
                    $maxItems = (int) $data['max_items'];
                    $transmit = $data['transmit'];

                    $results = [];

                    // 空ファイル
                    $results[] = $generator->generateEmptyFile($jxSettingId);

                    // 全商品ファイル
                    $results[] = $generator->generateFullOrderFile($jxSettingId, null, $maxItems);

                    // 集約テストファイル（発注先ごとの商品数 = maxItems / 5）
                    $results[] = $generator->generateAggregatedFile($jxSettingId, null, max(1, (int) ceil($maxItems / 5)));

                    $message = '生成完了: '.count($results)."ファイル\n\n";

                    foreach ($results as $result) {
                        $message .= "- {$result['pattern']}: {$result['filename']} ({$result['file_size']} bytes)\n";

                        if ($transmit) {
                            $transmitResult = $generator->transmitFile($jxSettingId, $result['content']);
                            if ($transmitResult->succeeded()) {
                                $message .= "  送信成功\n";
                            } else {
                                $message .= "  送信失敗: {$transmitResult->errorMessage}\n";
                            }
                        }
                    }

                    Notification::make()
                        ->title('全パターンを生成しました')
                        ->body($message)
                        ->success()
                        ->send();

                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エラー')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * JX設定一覧を取得
     */
    public function getJxSettings(): array
    {
        return WmsOrderJxSetting::where('is_active', true)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'sender_station_code' => $s->sender_station_code,
                'receiver_station_code' => $s->receiver_station_code,
                'endpoint_url' => $s->endpoint_url,
            ])
            ->toArray();
    }

    /**
     * 生成済みファイル一覧を取得（S3）
     */
    public function getGeneratedFiles(): array
    {
        $files = Storage::disk('s3')->files('jx-test');

        return collect($files)
            ->map(function ($path) {
                $filename = basename($path);
                $size = Storage::disk('s3')->size($path);
                $lastModified = Storage::disk('s3')->lastModified($path);

                return [
                    'filename' => $filename,
                    'path' => $path,
                    'size' => $size,
                    'size_formatted' => number_format($size).' bytes',
                    'last_modified_timestamp' => $lastModified,
                    'last_modified' => date('Y-m-d H:i:s', $lastModified),
                ];
            })
            ->sortByDesc('last_modified_timestamp')
            ->values()
            ->take(20)
            ->toArray();
    }

    /**
     * 最近の送信ログを取得
     */
    public function getRecentTransmissionLogs(): array
    {
        return WmsJxTransmissionLog::with('jxSetting')
            ->where('operation_type', 'PutDocument')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'jx_setting_name' => $log->jxSetting?->name ?? 'N/A',
                'status' => $log->status,
                'message_id' => $log->message_id,
                'data_size' => $log->data_size,
                'file_path' => $log->file_path,
                'created_at' => $log->created_at->format('Y-m-d H:i:s'),
            ])
            ->toArray();
    }

    /**
     * 送信済みファイルをダウンロード（S3）
     */
    public function downloadTransmittedFile(int $logId): \Illuminate\Http\RedirectResponse
    {
        $log = WmsJxTransmissionLog::findOrFail($logId);

        if (! $log->file_path) {
            abort(404, 'ファイルパスが記録されていません');
        }

        // file_path format: "s3:jx-client/data/2026-02-02/01/timestamp_messageId.dat"
        // 旧形式 "local:" もサポート
        $path = preg_replace('/^(local|s3):/', '', $log->file_path);

        if (! Storage::disk('s3')->exists($path)) {
            abort(404, 'ファイルが見つかりません');
        }

        $url = Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    }

    /**
     * ファイルダウンロード（S3）
     */
    public function downloadFile(string $filename): \Illuminate\Http\RedirectResponse
    {
        $path = "jx-test/{$filename}";

        if (! Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $url = Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    }

    /**
     * テストサーバ受信ファイル一覧を取得（S3）
     */
    public function getTestServerReceivedFiles(): array
    {
        $baseDir = 'jx-server/documents';

        // S3から全ファイルを取得
        $allFiles = Storage::disk('s3')->allFiles($baseDir);

        if (empty($allFiles)) {
            return [];
        }

        $files = [];
        foreach ($allFiles as $filePath) {
            $filename = basename($filePath);
            $size = Storage::disk('s3')->size($filePath);
            $lastModified = Storage::disk('s3')->lastModified($filePath);

            // パスから日付を抽出（jx-server/documents/2026-02-02/file.xml）
            $pathParts = explode('/', $filePath);
            $date = $pathParts[2] ?? '';

            $files[] = [
                'filename' => $filename,
                'path' => $filePath,
                'date' => $date,
                'size' => $size,
                'size_formatted' => number_format($size).' bytes',
                'last_modified_timestamp' => $lastModified,
                'last_modified' => date('Y-m-d H:i:s', $lastModified),
            ];
        }

        // 最新順にソート（タイムスタンプで比較）
        usort($files, fn ($a, $b) => $b['last_modified_timestamp'] <=> $a['last_modified_timestamp']);

        return array_slice($files, 0, 20);
    }

    /**
     * テストサーバ受信ファイルをダウンロード（S3）
     */
    public function downloadTestServerFile(string $path): \Illuminate\Http\RedirectResponse
    {
        if (! Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $url = Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    }

    /**
     * 送信XMLファイル一覧を取得（S3）
     */
    public function getSentXmlFiles(): array
    {
        $baseDir = 'jx-client/requests';

        // S3から全ファイルを取得
        $allFiles = Storage::disk('s3')->allFiles($baseDir);

        if (empty($allFiles)) {
            return [];
        }

        $files = [];
        foreach ($allFiles as $filePath) {
            $filename = basename($filePath);
            $size = Storage::disk('s3')->size($filePath);
            $lastModified = Storage::disk('s3')->lastModified($filePath);

            // パスから日付とドキュメントタイプを抽出
            // jx-client/requests/2026-02-02/putdocument/20260202152329/file.xml
            $pathParts = explode('/', $filePath);
            $date = $pathParts[2] ?? '';
            $docType = $pathParts[3] ?? '';

            $files[] = [
                'filename' => $filename,
                'path' => $filePath,
                'date' => $date,
                'doc_type' => $docType,
                'size' => $size,
                'size_formatted' => number_format($size).' bytes',
                'last_modified_timestamp' => $lastModified,
                'last_modified' => date('Y-m-d H:i:s', $lastModified),
            ];
        }

        // 最新順にソート
        usort($files, fn ($a, $b) => $b['last_modified_timestamp'] <=> $a['last_modified_timestamp']);

        return array_slice($files, 0, 30);
    }

    /**
     * 送信XMLファイルの内容を取得（S3）
     */
    public function getXmlContent(string $path): string
    {
        if (! Storage::disk('s3')->exists($path)) {
            return 'ファイルが見つかりません';
        }

        return Storage::disk('s3')->get($path);
    }

    /**
     * 送信XMLファイルをダウンロード（S3）
     */
    public function downloadXmlFile(string $path): \Illuminate\Http\RedirectResponse
    {
        if (! Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $url = Storage::disk('s3')->temporaryUrl($path, now()->addHour());

        return redirect($url);
    }
}
