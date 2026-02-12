<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Models\Sakemaru\Warehouse;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehouseSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'warehouseSettings';

    protected static ?string $title = '倉庫別納入先指定コード';

    protected static ?string $modelLabel = '納入先指定コード';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('warehouse_id')
                    ->label('倉庫')
                    ->options(fn () => Warehouse::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"]))
                    ->searchable()
                    ->required()
                    ->disabledOn('edit'),

                TextInput::make('designated_code')
                    ->label('納入先指定コード')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('warehouse_id')
            ->columns([
                TextColumn::make('warehouse.code')
                    ->label('倉庫CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('warehouse.name')
                    ->label('倉庫名')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('designated_code')
                    ->label('納入先指定コード')
                    ->placeholder('-'),
            ])
            ->defaultSort('warehouse.code')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['contractor_id'] = $this->getOwnerRecord()->id;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
