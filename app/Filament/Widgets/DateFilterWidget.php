<?php

namespace App\Filament\Widgets;

use App\Models\Sakemaru\ClientSetting;
use Filament\Widgets\Widget;

/**
 * グローバル日付フィルタウィジェット
 *
 * ページ上部に日付ピッカーを配置し、filter-date-updated イベントを
 * dispatch して同一ページ内の他ウィジェットに基準日を通知する。
 *
 * 使い方:
 *   getHeaderWidgets() の先頭に配置
 *   他ウィジェットは #[On('filter-date-updated')] でリッスン
 */
class DateFilterWidget extends Widget
{
    protected string $view = 'filament.widgets.date-filter-widget';

    protected int|string|array $columnSpan = 'full';

    public string $filterDate = '';

    public function mount(): void
    {
        if (empty($this->filterDate)) {
            $this->filterDate = ClientSetting::systemDateYMD();
        }
    }

    public function updatedFilterDate(): void
    {
        $this->dispatch('filter-date-updated', filterDate: $this->filterDate);
    }
}
