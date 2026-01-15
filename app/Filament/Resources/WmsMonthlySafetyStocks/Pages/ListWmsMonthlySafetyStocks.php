<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Pages;

use App\Filament\Resources\WmsMonthlySafetyStocks\WmsMonthlySafetyStockResource;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Item;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsMonthlySafetyStock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ListWmsMonthlySafetyStocks extends ListRecords
{
    protected static string $resource = WmsMonthlySafetyStockResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCsv')
                ->label('CSVインポート')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->schema([
                    FileUpload::make('csv_file')
                        ->label('CSVファイル')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->required()
                        ->disk('local')
                        ->directory('csv-imports')
                        ->helperText('フォーマット: item_code,warehouse_code,contractor_code,month,safety_stock'),
                ])
                ->action(function (array $data) {
                    $this->importCsv($data['csv_file']);
                }),

            Action::make('downloadTemplate')
                ->label('テンプレート')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $headers = ['item_code', 'warehouse_code', 'contractor_code', 'month', 'safety_stock'];
                    $example = ['10001', 'WH001', 'CNT001', '1', '100'];

                    $csv = implode(',', $headers) . "\n" . implode(',', $example) . "\n";

                    return response()->streamDownload(function () use ($csv) {
                        echo "\xEF\xBB\xBF"; // BOM for Excel
                        echo $csv;
                    }, 'monthly_safety_stocks_template.csv');
                }),

            CreateAction::make(),
        ];
    }

    private function importCsv(string $filePath): void
    {
        $fullPath = Storage::disk('local')->path($filePath);

        if (! file_exists($fullPath)) {
            Notification::make()
                ->title('ファイルが見つかりません')
                ->danger()
                ->send();

            return;
        }

        $content = file_get_contents($fullPath);

        // BOMを除去
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Shift-JISの場合はUTF-8に変換
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
        }

        $lines = array_filter(explode("\n", $content));

        if (count($lines) < 2) {
            Notification::make()
                ->title('データがありません')
                ->danger()
                ->send();

            return;
        }

        // ヘッダー行をスキップ
        array_shift($lines);

        $imported = 0;
        $errors = [];

        // マスタデータをキャッシュ
        $items = Item::query()->pluck('id', 'code');
        $warehouses = Warehouse::query()->pluck('id', 'code');
        $contractors = Contractor::query()->pluck('id', 'code');

        DB::beginTransaction();

        try {
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $cols = str_getcsv($line);

                if (count($cols) < 5) {
                    $errors[] = "行" . ($lineNum + 2) . ": カラム数が不足";

                    continue;
                }

                [$itemCode, $warehouseCode, $contractorCode, $month, $safetyStock] = $cols;

                // マスタチェック
                $itemId = $items[$itemCode] ?? null;
                $warehouseId = $warehouses[$warehouseCode] ?? null;
                $contractorId = $contractors[$contractorCode] ?? null;

                if (! $itemId) {
                    $errors[] = "行" . ($lineNum + 2) . ": 商品コード {$itemCode} が見つかりません";

                    continue;
                }
                if (! $warehouseId) {
                    $errors[] = "行" . ($lineNum + 2) . ": 倉庫コード {$warehouseCode} が見つかりません";

                    continue;
                }
                if (! $contractorId) {
                    $errors[] = "行" . ($lineNum + 2) . ": 発注先コード {$contractorCode} が見つかりません";

                    continue;
                }

                $month = (int) $month;
                if ($month < 1 || $month > 12) {
                    $errors[] = "行" . ($lineNum + 2) . ": 月が不正です ({$month})";

                    continue;
                }

                $safetyStock = (int) $safetyStock;
                if ($safetyStock < 0) {
                    $errors[] = "行" . ($lineNum + 2) . ": 発注点が負の値です";

                    continue;
                }

                // Upsert
                WmsMonthlySafetyStock::updateOrCreate(
                    [
                        'item_id' => $itemId,
                        'warehouse_id' => $warehouseId,
                        'contractor_id' => $contractorId,
                        'month' => $month,
                    ],
                    [
                        'safety_stock' => $safetyStock,
                    ]
                );

                $imported++;
            }

            DB::commit();

            // ファイル削除
            Storage::disk('local')->delete($filePath);

            $message = "{$imported}件をインポートしました。";
            if (! empty($errors)) {
                $message .= "\nエラー: " . count($errors) . "件";
                Log::warning('月別発注点CSVインポートエラー', ['errors' => $errors]);
            }

            Notification::make()
                ->title('インポート完了')
                ->body($message)
                ->success()
                ->send();

        } catch (\Exception $e) {
            DB::rollBack();
            Storage::disk('local')->delete($filePath);

            Log::error('月別発注点CSVインポート失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('インポート失敗')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
