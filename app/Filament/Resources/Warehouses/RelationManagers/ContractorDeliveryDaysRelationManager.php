<?php

namespace App\Filament\Resources\Warehouses\RelationManagers;

use App\Models\Sakemaru\Contractor;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContractorDeliveryDaysRelationManager extends RelationManager
{
    protected static string $relationship = 'contractorDeliveryDays';

    protected static ?string $title = '発注先別納品可能曜日';

    protected static ?string $modelLabel = '納品可能曜日';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('contractor_id')
                    ->label('発注先')
                    ->options(fn () => Contractor::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->id => "[{$c->code}] {$c->name}"]))
                    ->searchable()
                    ->required()
                    ->disabledOn('edit'),

                Fieldset::make('納品可能曜日')
                    ->schema([
                        Toggle::make('delivery_mon')->label('月')->inline(false)->default(false),
                        Toggle::make('delivery_tue')->label('火')->inline(false)->default(false),
                        Toggle::make('delivery_wed')->label('水')->inline(false)->default(false),
                        Toggle::make('delivery_thu')->label('木')->inline(false)->default(false),
                        Toggle::make('delivery_fri')->label('金')->inline(false)->default(false),
                        Toggle::make('delivery_sat')->label('土')->inline(false)->default(false),
                        Toggle::make('delivery_sun')->label('日')->inline(false)->default(false),
                    ])
                    ->columns(7),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('contractor_id')
            ->columns([
                TextColumn::make('contractor.code')
                    ->label('発注先CD')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('contractor.name')
                    ->label('発注先名')
                    ->sortable()
                    ->searchable(),

                IconColumn::make('delivery_mon')
                    ->label('月')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('delivery_tue')
                    ->label('火')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('delivery_wed')
                    ->label('水')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('delivery_thu')
                    ->label('木')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('delivery_fri')
                    ->label('金')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('delivery_sat')
                    ->label('土')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('delivery_sun')
                    ->label('日')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('contractor.code')
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['warehouse_id'] = $this->getOwnerRecord()->id;
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
