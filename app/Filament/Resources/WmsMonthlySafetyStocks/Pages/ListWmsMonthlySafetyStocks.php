<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Pages;

use App\Filament\Resources\WmsMonthlySafetyStocks\Schemas\WmsMonthlySafetyStockForm;
use App\Filament\Resources\WmsMonthlySafetyStocks\WmsMonthlySafetyStockResource;
use App\Jobs\ImportMonthlySafetyStocksCsvJob;
use App\Jobs\ImportOrderPointAnalysisCsvJob;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsImportLog;
use App\Models\WmsMonthlySafetyStock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

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
                        ->maxSize(512000) // 500MB
                        ->required()
                        ->disk('local')
                        ->directory('csv-imports')
                        ->helperText('フォーマット: item_code,warehouse_code,contractor_code,month,safety_stock'),
                ])
                ->action(function (array $data) {
                    $this->dispatchImportJob($data['csv_file']);
                }),

            Action::make('importAnalysisCsv')
                ->label('発注点分析CSVインポート')
                ->icon('heroicon-o-calculator')
                ->color('warning')
                ->schema([
                    FileUpload::make('analysis_csv_file')
                        ->label('発注点分析CSVファイル')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->maxSize(512000)
                        ->required()
                        ->disk('local')
                        ->directory('csv-imports')
                        ->helperText('HanaDB発注点分析CSV（store_code,item_code,...,safety_stock,order_point,...）'),

                    Select::make('warehouse_id')
                        ->label('対象倉庫')
                        ->options(fn () => Warehouse::pluck('name', 'id'))
                        ->required()
                        ->searchable()
                        ->helperText('インポート先の倉庫を選択'),

                    Select::make('month_mode')
                        ->label('対象月')
                        ->options([
                            'all' => '全月（1〜12月）一括',
                            'single' => '特定の月のみ',
                        ])
                        ->required()
                        ->default('all')
                        ->live(),

                    Select::make('month')
                        ->label('月')
                        ->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => $m.'月']))
                        ->visible(fn (callable $get) => $get('month_mode') === 'single')
                        ->requiredIf('month_mode', 'single'),

                    Select::make('value_column')
                        ->label('インポートする値')
                        ->options([
                            'order_point' => '発注点（order_point）',
                            'safety_stock' => '安全在庫（safety_stock）',
                        ])
                        ->default('order_point')
                        ->required()
                        ->helperText('発注点 = リードタイム × 平均日販 + 安全在庫'),
                ])
                ->action(function (array $data) {
                    $this->dispatchAnalysisCsvImportJob($data);
                }),

            Action::make('downloadTemplate')
                ->label('テンプレート')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $headers = ['item_code', 'warehouse_code', 'contractor_code', 'month', 'safety_stock'];
                    $example = ['10001', 'WH001', 'CNT001', '1', '100'];

                    $csv = implode(',', $headers)."\n".implode(',', $example)."\n";

                    return response()->streamDownload(function () use ($csv) {
                        echo "\xEF\xBB\xBF"; // BOM for Excel
                        echo $csv;
                    }, 'monthly_safety_stocks_template.csv');
                }),

            CreateAction::make()
                ->model(WmsMonthlySafetyStock::class)
                ->form(fn ($form) => WmsMonthlySafetyStockForm::configure($form)->getComponents())
                ->modalHeading('月別発注点を作成')
                ->successNotificationTitle('作成しました'),
        ];
    }

    private function dispatchAnalysisCsvImportJob(array $data): void
    {
        $months = $data['month_mode'] === 'all'
            ? range(1, 12)
            : [(int) $data['month']];

        $importLog = WmsImportLog::create([
            'type' => WmsImportLog::TYPE_MONTHLY_SAFETY_STOCKS,
            'status' => WmsImportLog::STATUS_PENDING,
            'file_name' => basename($data['analysis_csv_file']),
            'user_id' => auth()->id(),
        ]);

        ImportOrderPointAnalysisCsvJob::dispatch(
            $data['analysis_csv_file'],
            $importLog->id,
            (int) $data['warehouse_id'],
            $months,
            $data['value_column'],
        );

        Notification::make()
            ->title('発注点分析CSVインポートを開始しました')
            ->body('バックグラウンドで処理中です。完了まで数分かかる場合があります。')
            ->info()
            ->send();
    }

    private function dispatchImportJob(string $filePath): void
    {
        // インポートログを作成
        $importLog = WmsImportLog::create([
            'type' => WmsImportLog::TYPE_MONTHLY_SAFETY_STOCKS,
            'status' => WmsImportLog::STATUS_PENDING,
            'file_name' => basename($filePath),
            'user_id' => auth()->id(),
        ]);

        // Jobをディスパッチ
        ImportMonthlySafetyStocksCsvJob::dispatch($filePath, $importLog->id);

        Notification::make()
            ->title('インポートを開始しました')
            ->body('バックグラウンドで処理中です。完了まで数分かかる場合があります。')
            ->info()
            ->send();
    }
}
