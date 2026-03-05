<?php

namespace App\Filament\Resources\WmsIncomingReceivedData\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsIncomingReceivedData\WmsIncomingReceivedDataResource;
use App\Services\AutoOrder\IncomingReceiveService;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsIncomingReceivedData extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsIncomingReceivedDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadJxFile')
                ->label('JXデータ取込')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('JX納品データの取込')
                ->modalDescription('JX納品伝票データファイル（Shift_JIS固定長128バイト）をアップロードしてください。FINETラッパーの有無は自動判定されます。')
                ->schema([
                    FileUpload::make('jx_file')
                        ->label('JXデータファイル')
                        ->required()
                        ->disk('local')
                        ->directory('temp/jx-incoming')
                        ->acceptedFileTypes(['text/plain', 'application/octet-stream', '.dat', '.txt'])
                        ->maxSize(10240),
                ])
                ->action(function (array $data) {
                    $filePath = storage_path('app/private/' . $data['jx_file']);
                    if (! file_exists($filePath)) {
                        // Filament 4ではprivateなしのパスの場合もある
                        $filePath = storage_path('app/' . $data['jx_file']);
                    }

                    if (! file_exists($filePath)) {
                        Notification::make()
                            ->title('ファイルが見つかりません')
                            ->danger()
                            ->send();

                        return;
                    }

                    $content = file_get_contents($filePath);
                    $filename = basename($data['jx_file']);

                    $service = app(IncomingReceiveService::class);

                    try {
                        $file = $service->parseJxData($content, $filename);

                        Notification::make()
                            ->title('JXデータを取り込みました')
                            ->body("伝票数: {$file->parsed_slip_count}件 / 明細数: {$file->parsed_detail_count}件")
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('取込エラー')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    } finally {
                        // 一時ファイル削除
                        @unlink($filePath);
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('slips')
                ->orderBy('id', 'desc')
            );
    }

    public function getPresetViews(): array
    {
        return [
            'all' => PresetView::make()
                ->favorite()
                ->label('全て')
                ->default(),

            'pending' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'PENDING'))
                ->favorite()
                ->label('未照合'),

            'matched' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'MATCHED'))
                ->favorite()
                ->label('照合済み'),

            'applied' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'APPLIED'))
                ->favorite()
                ->label('適用済み'),
        ];
    }
}
