<?php

namespace App\Filament\Concerns;

use App\Enums\ExportFormat;
use App\Models\WmsExportLog;
use App\Services\Export\ExportService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

trait HasExportAction
{
    public static function getExportAction(): Action
    {
        return Action::make('export')
            ->label('ダウンロード')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->schema(fn (HasTable $livewire) => [
                TextInput::make('file_name')
                    ->label('ファイル名')
                    ->default(static::getDefaultExportFileName($livewire))
                    ->required()
                    ->maxLength(200)
                    ->helperText('拡張子は自動で付与されます'),

                Select::make('format')
                    ->label('ファイル形式')
                    ->options([
                        'csv' => 'CSV',
                        'xlsx' => 'Excel (XLSX)',
                    ])
                    ->default('xlsx')
                    ->required(),
            ])
            ->action(function (array $data, HasTable $livewire, Table $table) {
                $format = ExportFormat::from($data['format']);
                $query = $livewire->getFilteredSortedTableQuery();

                // テーブルの可視カラムからエクスポート用カラム定義を構築
                $columns = [];
                foreach ($table->getVisibleColumns() as $column) {
                    $label = $column->getLabel();
                    $name = $column->getName();

                    // ラベルが文字列であることを確認
                    if ($label instanceof \Illuminate\Contracts\Support\Htmlable) {
                        $label = strip_tags($label->toHtml());
                    }

                    $columns[(string) $label] = $name;
                }

                if (empty($columns)) {
                    Notification::make()
                        ->title('エクスポートするカラムがありません')
                        ->danger()
                        ->send();

                    return;
                }

                // リソースの日本語ページタイトルを取得
                $resourceName = static::resolveResourceName($livewire, $table);

                $userId = auth()->id();
                $customFileName = $data['file_name'];

                $exportService = app(ExportService::class);

                try {
                    $result = $exportService->export(
                        $query,
                        $columns,
                        $format,
                        $resourceName,
                        $userId,
                        customFileName: $customFileName,
                    );

                    if ($result instanceof WmsExportLog) {
                        Notification::make()
                            ->title('エクスポートを開始しました')
                            ->body('データ件数が多いためバックグラウンドで処理しています。完了後、ダウンロードログ画面からダウンロードできます。')
                            ->info()
                            ->send();

                        return;
                    }

                    return $result;
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('エクスポートに失敗しました')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function resolveResourceName(HasTable $livewire, Table $table): string
    {
        if (method_exists($livewire, 'getResource')) {
            return $livewire::getResource()::getPluralModelLabel();
        }

        return $table->getModel()
            ? class_basename($table->getModel())
            : 'export';
    }

    private static function getDefaultExportFileName(HasTable $livewire): string
    {
        $name = 'export';
        if (method_exists($livewire, 'getResource')) {
            $name = $livewire::getResource()::getPluralModelLabel();
        }

        return $name.'_'.date('Ymd_His');
    }
}
