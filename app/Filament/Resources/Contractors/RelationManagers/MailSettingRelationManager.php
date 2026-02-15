<?php

namespace App\Filament\Resources\Contractors\RelationManagers;

use App\Enums\AutoOrder\TransmissionType;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class MailSettingRelationManager extends RelationManager
{
    protected static string $relationship = 'wmsMailSetting';

    protected static ?string $title = '発注メール設定';

    protected static ?string $modelLabel = 'メール設定';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-envelope';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1) // ★ これが重要
            ->components([
                Grid::make(2)->schema([

                    Section::make('メール設定')
                        ->schema([
                            TextInput::make('order_mail')->label('発注先メールアドレス')->email(),
                            TextInput::make('order_mail_from')->label('送信名'),
                            TextInput::make('order_mail_title')->label('メールタイトル'),
                            TextEntry::make('variables_help')
                                ->label('利用可能な変数')
                                ->state(new HtmlString(
                                    '<div class="text-xs text-gray-500 border rounded-lg p-3 bg-gray-50 dark:bg-gray-800 dark:border-gray-700">' . '<table>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_CONTRACTOR_NAME$$</td><td>発注先名</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_WAREHOUSE_NAME$$</td><td>倉庫名</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_ORDER_DATE$$</td><td>発注日（2026年02月14日）</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_ORDER_DATE_SHORT$$</td><td>発注日（2026/02/14）</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_EXPECTED_ARRIVAL_DATE$$</td><td>入荷予定日</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_ORDER_COUNT$$</td><td>発注件数</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_TOTAL_QUANTITY$$</td><td>合計数量</td></tr>' . '<tr><td class="font-mono pr-4 py-0.5">$$VAR_ATTACHMENTS$$</td><td>添付ファイル一覧</td></tr>' . '</table></div>'

                                ))
                        ]),

                    Section::make('メール本文')
                        ->schema([
                            Textarea::make('order_mail_content')
                                ->label('本文')
                                ->rows(24),
                        ]),

                ]),
            ]);
    }


    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('order_mail')
            ->columns([
                TextColumn::make('order_mail')
                    ->label('メールアドレス')
                    ->placeholder('未設定'),

                TextColumn::make('order_mail_from')
                    ->label('送信名')
                    ->placeholder('未設定'),

                TextColumn::make('order_mail_title')
                    ->label('タイトル')
                    ->limit(40)
                    ->placeholder('未設定'),

                TextColumn::make('order_mail_content')
                    ->label('本文')
                    ->limit(30)
                    ->placeholder('未設定'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->modalWidth('7xl')
                    ->mutateDataUsing(function (array $data): array {
                        $data['contractor_id'] = $this->getOwnerRecord()->id;
                        $data['transmission_type'] = TransmissionType::MANUAL_CSV->value;

                        return $data;
                    })
                    ->visible(fn() => !$this->getOwnerRecord()->wmsMailSetting()->exists()),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('7xl'),
            ]);
    }
}
