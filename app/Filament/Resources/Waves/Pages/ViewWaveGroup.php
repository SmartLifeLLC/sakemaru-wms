<?php

namespace App\Filament\Resources\Waves\Pages;

use App\Filament\Resources\Waves\Tables\WaveGroupsTable;
use App\Filament\Resources\Waves\WaveResource;
use App\Models\WaveGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;

class ViewWaveGroup extends ViewRecord
{
    protected static string $resource = WaveResource::class;

    protected string $view = 'filament.resources.waves.pages.view-wave-group';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->load([
            'warehouse',
            'creator',
            'waves.waveSetting.deliveryCourse.warehouse',
        ]);
    }

    public function getTitle(): string
    {
        return '波動生成グループ詳細';
    }

    public function getBreadcrumbs(): array
    {
        return [
            WaveResource::getUrl() => '波動生成グループ',
            '#' => $this->record->group_no,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadSavedPickingList')
                ->label('ピッキングリスト出力')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('info')
                ->visible(fn (): bool => ! empty($this->record->picking_lists))
                ->modalHeading(fn (): string => "ピッキングリスト出力: {$this->record->group_no}")
                ->modalDescription('リスト種別を選択し、保存済みの対象リストを出力します')
                ->modalWidth('6xl')
                ->extraModalWindowAttributes(['class' => 'picking-list-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn (Action $action) => $action->label('出力')->color('danger'))
                ->modalCancelActionLabel('出力せず閉じる')
                ->schema(fn (): array => [
                    ViewField::make('list_type')
                        ->label('リスト種別')
                        ->view('filament.forms.components.picking-list-type-select')
                        ->viewData([
                            'enabledTypes' => array_keys($this->record->picking_lists ?? []),
                        ])
                        ->required()
                        ->default(array_key_first($this->record->picking_lists ?? []))
                        ->live(),

                    ...WaveGroupsTable::printerSelectionSchema($this->record->warehouse_id),

                    Placeholder::make('wave_preview')
                        ->label('対象波動')
                        ->content(fn (): \Illuminate\Support\HtmlString => WaveGroupsTable::wavePreviewHtml($this->record)),
                ])
                ->action(fn (array $data) => WaveGroupsTable::downloadSavedPickingList($this->record, $data)),

            Action::make('waveGenerationProgress')
                ->label('生成状況')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->modalHeading(fn (): string => "波動生成状況: {$this->record->group_no}")
                ->modalWidth('4xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('閉じる')
                ->modalContent(fn (): \Illuminate\Support\HtmlString => WaveGroupsTable::waveGenerationProgressHtml($this->record)),

            Action::make('cancelWaveGroup')
                ->label('取消')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => $this->record->waves()->where('status', '!=', 'CLOSED')->exists())
                ->modalHeading(fn (): string => "生成グループ取消: {$this->record->group_no}")
                ->modalDescription('生成グループ配下の波動をまとめて取り消します。対象伝票はピッキング前に戻ります。')
                ->modalWidth('3xl')
                ->extraModalWindowAttributes(['class' => 'incoming-detail-modal'])
                ->modalFooterActionsAlignment(Alignment::End)
                ->modalSubmitAction(fn (Action $action) => $action->label('生成グループを取消')->color('danger'))
                ->modalCancelActionLabel('取消せず閉じる')
                ->modalContent(fn (): \Illuminate\Support\HtmlString => \App\Filament\Resources\Waves\Tables\WavesTable::bulkCancelWaveModalContent(
                    $this->record->waves()->orderBy('id')->get()
                ))
                ->action(fn () => WaveGroupsTable::cancelWaveGroup($this->record)),
        ];
    }

    public function getWaveGroup(): WaveGroup
    {
        return $this->record;
    }
}
