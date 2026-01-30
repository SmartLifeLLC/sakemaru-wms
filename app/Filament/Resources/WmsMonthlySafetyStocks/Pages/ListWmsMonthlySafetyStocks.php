<?php

namespace App\Filament\Resources\WmsMonthlySafetyStocks\Pages;

use App\Filament\Resources\WmsMonthlySafetyStocks\Schemas\WmsMonthlySafetyStockForm;
use App\Filament\Resources\WmsMonthlySafetyStocks\WmsMonthlySafetyStockResource;
use App\Jobs\ImportMonthlySafetyStocksCsvJob;
use App\Models\WmsImportLog;
use App\Models\WmsMonthlySafetyStock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
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
