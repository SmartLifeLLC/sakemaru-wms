<?php

namespace App\Filament\Resources\WmsPickers\Schemas;

use App\Enums\PickerSkillLevel;
use App\Models\WmsPickingArea;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WmsPickerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        TextInput::make('code')
                            ->label('ピッカーコード')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->placeholder('例: P001')
                            ->helperText('ピッカーを識別するための一意のコード'),

                        TextInput::make('name')
                            ->label('ピッカー名')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('例: 山田太郎'),

                        TextInput::make('password')
                            ->label('パスワード')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => $state ? Hash::make($state) : null)
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create')
                            ->maxLength(255)
                            ->placeholder('8文字以上推奨')
                            ->helperText(fn (string $context): string =>
                                $context === 'edit'
                                    ? '変更する場合のみ入力してください'
                                    : 'ピッカーがログインする際のパスワード'
                            ),
                    ])
                    ->columns(2),

                Section::make('設定')
                    ->schema([
                        Select::make('default_warehouse_id')
                            ->label('デフォルト倉庫')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->nullable()
                            ->helperText('このピッカーがメインで作業する倉庫'),

                        Select::make('skill_level')
                            ->label('スキルレベル')
                            ->options(PickerSkillLevel::options())
                            ->default(PickerSkillLevel::SENIOR->value)
                            ->required()
                            ->helperText(function ($state) {
                                if (!$state) return 'ピッカーのスキルレベルを選択';
                                $level = PickerSkillLevel::tryFrom((int) $state);
                                return $level?->description() ?? '';
                            }),

                        TextInput::make('picking_speed_rate')
                            ->label('作業速度係数')
                            ->numeric()
                            ->default(1.00)
                            ->step(0.1)
                            ->minValue(0.5)
                            ->maxValue(2.0)
                            ->suffix('x')
                            ->helperText('0.5〜2.0 (1.0が標準速度)'),

                        Toggle::make('can_access_restricted_area')
                            ->label('制限エリアアクセス可')
                            ->default(false)
                            ->helperText('有効にすると、制限エリアでの作業が可能になります'),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true)
                            ->helperText('無効にすると、このピッカーは選択できなくなります'),
                    ])
                    ->columns(3),

                Section::make('当日稼働状況')
                    ->schema([
                        Toggle::make('is_available_for_picking')
                            ->label('ピッキング稼働可')
                            ->default(false)
                            ->helperText('出勤かつ割当可能な場合にONにします'),

                        Select::make('current_warehouse_id')
                            ->label('現在稼働中の倉庫')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where('is_active', true)
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->nullable()
                            ->helperText('現在このピッカーが作業中の倉庫 (未割当ならなし)'),
                    ])
                    ->columns(2),

                Section::make('担当ピッキングエリア')
                    ->schema([
                        Select::make('area_warehouse_filter')
                            ->label('倉庫で絞り込み')
                            ->options(function () {
                                return DB::connection('sakemaru')
                                    ->table('warehouses')
                                    ->where('is_active', true)
                                    ->pluck('name', 'id')
                                    ->prepend('すべて', '');
                            })
                            ->default('')
                            ->live()
                            ->dehydrated(false),

                        CheckboxList::make('pickingAreas')
                            ->label('')
                            ->relationship('pickingAreas', 'name')
                            ->options(function (Get $get) {
                                $warehouseFilter = $get('area_warehouse_filter');

                                $query = WmsPickingArea::where('is_active', true);

                                if ($warehouseFilter) {
                                    $query->where('warehouse_id', $warehouseFilter);
                                }

                                return $query
                                    ->orderBy('warehouse_id')
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(function ($area) {
                                        $warehouseName = DB::connection('sakemaru')
                                            ->table('warehouses')
                                            ->where('id', $area->warehouse_id)
                                            ->value('name') ?? '不明';
                                        $restrictedBadge = $area->is_restricted_area ? ' [制限]' : '';
                                        return [$area->id => "[{$warehouseName}] {$area->name}{$restrictedBadge}"];
                                    });
                            })
                            ->columns(2)
                            ->searchable()
                            ->bulkToggleable()
                            ->helperText('このピッカーが自動割当時に担当できるエリアを選択してください'),
                    ])
                    ->collapsible(),
            ]);
    }
}
