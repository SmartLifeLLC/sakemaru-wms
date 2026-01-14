<?php

namespace App\Filament\Resources\Contractors\Schemas;

use App\Models\Sakemaru\LeadTime;
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
                    ->columns(3),

                Section::make('設定')
                    ->schema([
                        Select::make('lead_time_id')
                            ->label('リードタイム')
                            ->options(fn () => LeadTime::get()
                                ->mapWithKeys(fn ($lt) => [
                                    $lt->id => "コード:{$lt->code} (日:{$lt->lead_time_sun} 月:{$lt->lead_time_mon} 火:{$lt->lead_time_tue} 水:{$lt->lead_time_wed} 木:{$lt->lead_time_thu} 金:{$lt->lead_time_fri} 土:{$lt->lead_time_sat})",
                                ])
                            )
                            ->searchable()
                            ->nullable(),

                        Toggle::make('is_active')
                            ->label('有効')
                            ->default(true)
                            ->helperText('無効にすると選択できなくなります'),
                    ])
                    ->columns(2),
            ]);
    }
}
