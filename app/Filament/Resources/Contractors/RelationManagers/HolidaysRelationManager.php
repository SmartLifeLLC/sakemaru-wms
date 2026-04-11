<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidaysRelationManager extends RelationManager
{
    protected static string $relationship = 'holidays';

    protected static ?string $title = '発注先休日';

    protected static ?string $modelLabel = '休日';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('holiday_date')
                    ->label('休業日')
                    ->required(),

                TextInput::make('reason')
                    ->label('休業理由')
                    ->maxLength(255)
                    ->placeholder('例: 棚卸し、年末年始休業'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('holiday_date')
            ->columns([
                TextColumn::make('holiday_date')
                    ->label('休業日')
                    ->date('Y-m-d (D)')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('休業理由')
                    ->limit(50)
                    ->placeholder('-'),

                TextColumn::make('created_at')
                    ->label('作成日')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('holiday_date', 'desc')
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
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
