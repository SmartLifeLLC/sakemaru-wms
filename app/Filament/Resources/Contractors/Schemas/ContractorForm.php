<?php

namespace App\Filament\Resources\Contractors\Schemas;

use App\Models\Sakemaru\LeadTime;
use App\Models\Sakemaru\Supplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContractorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('基本情報')
                    ->schema([
                        TextInput::make('code')
                            ->label('発注先コード')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->placeholder('例: C001'),

                        TextInput::make('name')
                            ->label('発注先名')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('例: 株式会社サンプル'),

                        TextInput::make('nickname')
                            ->label('略称')
                            ->maxLength(255)
                            ->placeholder('例: サンプル'),

                        TextInput::make('kana_name')
                            ->label('カナ名')
                            ->maxLength(255)
                            ->placeholder('例: カブシキガイシャサンプル'),
                    ])
                    ->columns(2),

                Section::make('連絡先')
                    ->schema([
                        TextInput::make('postal_code')
                            ->label('郵便番号')
                            ->maxLength(10)
                            ->placeholder('例: 123-4567'),

                        TextInput::make('address1')
                            ->label('住所1')
                            ->maxLength(255)
                            ->placeholder('例: 東京都渋谷区'),

                        TextInput::make('address2')
                            ->label('住所2')
                            ->maxLength(255)
                            ->placeholder('例: 1-2-3 サンプルビル'),

                        TextInput::make('tel')
                            ->label('電話番号')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('例: 03-1234-5678'),

                        TextInput::make('fax')
                            ->label('FAX')
                            ->maxLength(20)
                            ->placeholder('例: 03-1234-5679'),
                    ])
                    ->columns(2),

                Section::make('設定')
                    ->schema([
                        Select::make('supplier_id')
                            ->label('仕入先')
                            ->options(fn () => Supplier::pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),

                        Select::make('lead_time_id')
                            ->label('リードタイム')
                            ->options(fn () => LeadTime::pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),

                        Select::make('delivery_type')
                            ->label('配送タイプ')
                            ->options([
                                0 => '通常',
                                1 => '特急',
                            ])
                            ->default(0),

                        Toggle::make('is_auto_change_order')
                            ->label('自動発注対象')
                            ->default(false)
                            ->helperText('自動発注の対象にする場合はONにします'),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true)
                            ->helperText('無効にすると選択できなくなります'),
                    ])
                    ->columns(3),
            ]);
    }
}
