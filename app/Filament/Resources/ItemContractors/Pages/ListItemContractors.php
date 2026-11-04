<?php

namespace App\Filament\Resources\ItemContractors\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\ItemContractors\ItemContractorResource;
use App\Models\Sakemaru\Warehouse;
use App\Services\ItemContractorNoteImport;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListItemContractors extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = ItemContractorResource::class;

    protected static ?string $title = '商品発注先一覧';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importNotes')
                ->label('備考更新')
                ->color('success')
                ->authorize(fn () => ItemContractorResource::can('update') && (int) auth()->user()?->client_id > 0)
                ->modalHeading('商品発注管理の備考更新')
                ->modalWidth('5xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn ($action) => $action->label('備考を更新')->color('danger'))
                ->modalCancelActionLabel('更新せず閉じる')
                ->schema(function (): array {
                    $warehouses = app(ItemContractorNoteImport::class)->warehouseOptions((int) auth()->user()?->client_id);

                    return [
                        ViewField::make('warehouse_ids')
                            ->label('対象倉庫')
                            ->view('filament.forms.components.warehouse-card-grid')
                            ->viewData(['warehouses' => $warehouses])
                            ->default(array_column($warehouses, 'id'))
                            ->rules(['array', 'list'])
                            ->required()
                            ->validationMessages(['required' => '対象倉庫を1つ以上選択してください。']),
                        FileUpload::make('file')
                            ->label('備考更新ファイル')
                            ->helperText('Excel（.xls、.xlsx）の先頭シートまたはCSV（.csv）の1行目に「商品コード」「備考」を入れてください。選択した倉庫の備考を更新します。備考が空欄の行は備考を消去します。')
                            ->maxSize(5120)
                            ->storeFiles(false)
                            ->required(),
                    ];
                })
                ->action(function (array $data, Actions\Action $action): void {
                    try {
                        $file = $data['file'] ?? null;
                        if (! $file instanceof TemporaryUploadedFile) {
                            throw new InvalidArgumentException('備考更新ファイルを選択してください。');
                        }
                        $count = app(ItemContractorNoteImport::class)->import(
                            (int) auth()->user()->client_id,
                            $data['warehouse_ids'] ?? [],
                            $file->getRealPath(),
                            pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION),
                        );
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->danger()->title('備考を更新できませんでした')
                            ->body($exception->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title("備考を{$count}件更新しました")->send();
                }),
            Actions\CreateAction::make()
                ->label('新規作成'),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['item', 'warehouse', 'contractor', 'supplier.partner'])
            );
    }

    public function getPresetViews(): array
    {
        $userDefaultWarehouseId = auth()->user()?->default_warehouse_id;

        // item_contractorsに存在する倉庫を取得
        $warehouses = Warehouse::whereIn('id', function ($query) {
            $query->select('warehouse_id')
                ->from('item_contractors')
                ->distinct();
        })
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $warehouseIds = $warehouses->pluck('id')->toArray();
        $hasDefaultWarehouse = $userDefaultWarehouseId && in_array($userDefaultWarehouseId, $warehouseIds);
        $defaultWarehouse = $hasDefaultWarehouse ? $warehouses->firstWhere('id', $userDefaultWarehouseId) : null;

        // デフォルトタブ
        if ($defaultWarehouse) {
            $views = [
                'default' => PresetView::make()
                    ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $userDefaultWarehouseId))
                    ->favorite()
                    ->label($defaultWarehouse->name)
                    ->default(),
            ];
        } else {
            $views = [
                'default' => PresetView::make()
                    ->favorite()
                    ->label('全て')
                    ->default(),
            ];
        }

        // 倉庫別タブ
        foreach ($warehouses as $warehouse) {
            if ($hasDefaultWarehouse && $warehouse->id === $userDefaultWarehouseId) {
                continue;
            }

            $views["warehouse_{$warehouse->id}"] = PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
                ->favorite()
                ->label($warehouse->name);
        }

        return $views;
    }
}
