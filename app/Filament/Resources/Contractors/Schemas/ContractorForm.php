<?php

namespace App\Filament\Resources\Contractors\Schemas;

use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\LeadTime;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsOrderFtpSetting;
use App\Models\WmsOrderJxSetting;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ContractorForm
{
    /**
     * デフォルトのフォーム構成（CreateContractor用）
     * 全フィールドをフラットに配置
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...static::basicInfoSchema(),
                ...static::mailSchema(),
            ]);
    }

    /**
     * 基本情報 + WMS送信設定フィールド（Grid(2)で左右配置）
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function basicInfoSchema(): array
    {
        return [
            Grid::make(2)->schema([
                // 左カラム: 基本情報
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
                    ->columns(3),

                // 右カラム: WMS送信設定
                Section::make('WMS送信設定')
                    ->icon('heroicon-o-paper-airplane')
                    ->afterHeader([
                        Toggle::make('wms_is_auto_transmission')
                            ->label('自動生成')
                            ->default(false),
                    ])
                    ->schema([
                        Select::make('wms_transmission_type')
                            ->label('送信方式')
                            ->options(collect(TransmissionType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                            ->required()
                            ->live()
                            ->default(TransmissionType::MANUAL_CSV->value),

                        Select::make('wms_order_jx_setting_id')
                            ->label('JX設定')
                            ->options(fn () => WmsOrderJxSetting::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->visible(fn (Get $get) => $get('wms_transmission_type') === TransmissionType::JX_FINET->value)
                            ->required(fn (Get $get) => $get('wms_transmission_type') === TransmissionType::JX_FINET->value),

                        Select::make('wms_order_ftp_setting_id')
                            ->label('FTP設定')
                            ->options(fn () => WmsOrderFtpSetting::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->visible(fn (Get $get) => $get('wms_transmission_type') === TransmissionType::FTP->value)
                            ->required(fn (Get $get) => $get('wms_transmission_type') === TransmissionType::FTP->value),

                        Select::make('wms_supply_warehouse_id')
                            ->label('供給倉庫')
                            ->options(fn () => Warehouse::pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->visible(fn (Get $get) => $get('wms_transmission_type') === TransmissionType::INTERNAL->value)
                            ->required(fn (Get $get) => $get('wms_transmission_type') === TransmissionType::INTERNAL->value)
                            ->helperText('倉庫間移動の場合、供給元の倉庫を選択'),

                        Grid::make(2)->schema([
                            TextInput::make('wms_auto_order_generation_time')
                                ->label('自動発注生成時刻')
                                ->type('time')
                                ->nullable()
                                ->helperText('発注候補を自動生成する時刻'),

                            TextInput::make('wms_transmission_time')
                                ->label('送信時刻')
                                ->type('time')
                                ->nullable()
                                ->helperText('指定しない場合は手動送信'),
                        ]),

                        Fieldset::make('送信曜日')
                            ->schema([
                                Toggle::make('wms_is_transmission_mon')->label('月')->inline(false),
                                Toggle::make('wms_is_transmission_tue')->label('火')->inline(false),
                                Toggle::make('wms_is_transmission_wed')->label('水')->inline(false),
                                Toggle::make('wms_is_transmission_thu')->label('木')->inline(false),
                                Toggle::make('wms_is_transmission_fri')->label('金')->inline(false),
                                Toggle::make('wms_is_transmission_sat')->label('土')->inline(false),
                                Toggle::make('wms_is_transmission_sun')->label('日')->inline(false),
                            ])
                            ->columns(7),

                        Select::make('wms_transmission_contractor_id')
                            ->label('発注データ集約先')
                            ->options(fn () => Contractor::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->nullable()
                            ->helperText('指定した発注先の送信データに本発注先の発注データを集約する（一つのファイルで送信したい場合）'),

                        TextInput::make('wms_format_strategy_class')
                            ->label('フォーマット戦略クラス')
                            ->maxLength(255)
                            ->nullable()
                            ->visible(fn (Get $get) => in_array($get('wms_transmission_type'), [
                                TransmissionType::JX_FINET->value,
                                TransmissionType::FTP->value,
                            ])),
                    ]),
            ]),
        ];
    }

    /**
     * 発注メール設定フィールド
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function mailSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Section::make('メール設定')
                    ->schema([
                        TextInput::make('wms_order_mail')
                            ->label('発注先メールアドレス')
                            ->email(),
                        TextInput::make('wms_order_mail_from')
                            ->label('送信名'),
                        TextInput::make('wms_order_mail_title')
                            ->label('メールタイトル'),
                        TextEntry::make('variables_help')
                            ->label('利用可能な変数')
                            ->state(new HtmlString(
                                '<div class="text-xs text-gray-500 border rounded-lg p-3 bg-gray-50 dark:bg-gray-800 dark:border-gray-700">'
                                .'<table>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_CONTRACTOR_NAME$$</td><td>発注先名</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_WAREHOUSE_NAME$$</td><td>倉庫名</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_ORDER_DATE$$</td><td>発注日（2026年02月14日）</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_ORDER_DATE_SHORT$$</td><td>発注日（2026/02/14）</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_EXPECTED_ARRIVAL_DATE$$</td><td>入荷予定日</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_ORDER_COUNT$$</td><td>発注件数</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_TOTAL_QUANTITY$$</td><td>合計数量</td></tr>'
                                .'<tr><td class="font-mono pr-4 py-0.5">$$VAR_ATTACHMENTS$$</td><td>添付ファイル一覧</td></tr>'
                                .'</table></div>'
                            )),
                    ]),

                Section::make('メール本文')
                    ->schema([
                        Textarea::make('wms_order_mail_content')
                            ->label('本文')
                            ->rows(24),
                    ]),
            ]),
        ];
    }
}
