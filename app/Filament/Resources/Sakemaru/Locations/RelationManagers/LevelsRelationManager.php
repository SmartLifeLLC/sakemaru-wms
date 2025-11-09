<?php

namespace App\Filament\Resources\Sakemaru\Locations\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LevelsRelationManager extends RelationManager
{
    protected static string $relationship = 'levels';

    protected static ?string $title = 'WMS段管理';

    protected static ?string $modelLabel = 'WMS段';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('level_number')
                    ->label('段番号')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10)
                    ->default(fn () => $this->getOwnerRecord()->levels()->max('level_number') + 1 ?? 1),
                TextInput::make('name')
                    ->label('段名称')
                    ->maxLength(255),
                Select::make('available_quantity_flags')
                    ->label('引当可能単位')
                    ->options([
                        1 => 'ケース',
                        2 => 'バラ',
                        3 => 'ケース+バラ',
                        4 => 'ボール',
                        8 => '無し',
                    ])
                    ->default(fn () => $this->getOwnerRecord()->available_quantity_flags)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('level_number')
            ->columns([
                TextColumn::make('level_number')
                    ->label('段番号')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('段名称'),
                TextColumn::make('available_quantity_flags')
                    ->label('引当可能単位')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        1 => 'ケース',
                        2 => 'バラ',
                        3 => 'ケース+バラ',
                        4 => 'ボール',
                        8 => '無し',
                        default => (string) $state,
                    })
                    ->color(fn (int $state): string => match ($state) {
                        1 => 'info',
                        2 => 'success',
                        3 => 'warning',
                        4 => 'primary',
                        8 => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('作成日時')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('level_number')
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
