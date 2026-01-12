<?php

namespace App\Filament\Resources\WmsPickingAssignmentStrategy;

use App\Enums\EMenu;
use App\Enums\PaginationOptions;
use App\Enums\PickingStrategyType;
use App\Filament\Resources\WmsPickingAssignmentStrategy\Pages\CreateWmsPickingAssignmentStrategy;
use App\Filament\Resources\WmsPickingAssignmentStrategy\Pages\EditWmsPickingAssignmentStrategy;
use App\Filament\Resources\WmsPickingAssignmentStrategy\Pages\ListWmsPickingAssignmentStrategies;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsPickingAssignmentStrategy;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WmsPickingAssignmentStrategyResource extends Resource
{
    protected static ?string $model = WmsPickingAssignmentStrategy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'ピッキング割当戦略';

    protected static ?string $modelLabel = 'ピッキング割当戦略';

    protected static ?string $pluralModelLabel = 'ピッキング割当戦略';

    protected static ?string $slug = 'wms-picking-assignment-strategies';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_PICKING_ASSIGNMENT_STRATEGIES->category()->label();
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_PICKING_ASSIGNMENT_STRATEGIES->sort();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        Select::make('warehouse_id')
                            ->label('対象倉庫')
                            ->options(
                                Warehouse::where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"])
                                    ->toArray()
                            )
                            ->required()
                            ->searchable()
                            ->columnSpan(1),

                        TextInput::make('name')
                            ->label('戦略名')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),

                        Select::make('strategy_key')
                            ->label('戦略タイプ')
                            ->options(PickingStrategyType::options())
                            ->required()
                            ->columnSpan(1),

                        Textarea::make('description')
                            ->label('説明')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('パラメータ設定')
                    ->schema([
                        KeyValue::make('parameters')
                            ->label('パラメータ設定 (JSON)')
                            ->keyLabel('キー')
                            ->valueLabel('値')
                            ->addActionLabel('パラメータを追加')
                            ->reorderable()
                            ->columnSpanFull(),
                    ]),

                Section::make('ステータス')
                    ->schema([
                        Toggle::make('is_default')
                            ->label('デフォルト戦略')
                            ->helperText('この倉庫でデフォルトとして使用する戦略に設定します（倉庫ごとに1つのみ）'),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->striped()
            ->defaultPaginationPageOption(PaginationOptions::DEFAULT)
            ->paginationPageOptions(PaginationOptions::all())
            ->columns([
                TextColumn::make('warehouse.name')
                    ->label('対象倉庫')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('name')
                    ->label('戦略名')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('strategy_key')
                    ->label('戦略タイプ')
                    ->badge()
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('デフォルト')
                    ->boolean()
                    ->alignCenter(),

                ToggleColumn::make('is_active')
                    ->label('有効'),

                TextColumn::make('updated_at')
                    ->label('更新日時')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('倉庫')
                    ->options(
                        Warehouse::where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($w) => [$w->id => "[{$w->code}] {$w->name}"])
                            ->toArray()
                    )
                    ->searchable(),

                SelectFilter::make('strategy_key')
                    ->label('戦略タイプ')
                    ->options(PickingStrategyType::options()),

                SelectFilter::make('is_active')
                    ->label('ステータス')
                    ->options([
                        '1' => '有効',
                        '0' => '無効',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('warehouse_id', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsPickingAssignmentStrategies::route('/'),
            'create' => CreateWmsPickingAssignmentStrategy::route('/create'),
            'edit' => EditWmsPickingAssignmentStrategy::route('/{record}/edit'),
        ];
    }
}
