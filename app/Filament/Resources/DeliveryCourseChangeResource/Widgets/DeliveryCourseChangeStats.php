<?php

namespace App\Filament\Resources\DeliveryCourseChangeResource\Widgets;

use App\Filament\Resources\DeliveryCourseChangeResource\Pages\ListDeliveryCourseChanges;
use App\Models\Sakemaru\TradeItem;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DeliveryCourseChangeStats extends BaseWidget
{
    use InteractsWithPageTable;

    protected ?string $pollingInterval = null;

    protected function getTablePage(): string
    {
        return ListDeliveryCourseChanges::class;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $query = $this->getPageTableQuery();
        $db = \Illuminate\Support\Facades\DB::connection('sakemaru');

        // Use fromSub to handle the groupBy correctly
        // We need to clone the query because fromSub consumes it or we might modify it
        $subQuery = $query->clone();

        $totalAmount = $db->query()->fromSub($subQuery, 'sub')->sum('total');
        $tradeCount = $db->query()->fromSub($subQuery, 'sub')->count();
        $courseCount = $db->query()->fromSub($subQuery, 'sub')->distinct()->count('current_course_id');

        // For detail count, we get the trade IDs from the subquery
        $detailCount = TradeItem::whereIn('trade_id', $db->query()->fromSub($subQuery, 'sub')->select('id'))
            ->where('is_active', true)
            ->count();

        return [
            Stat::make('合計金額', number_format($totalAmount).'円')->extraAttributes(['class' => 'text-center !py-1 !min-h-0']),
            Stat::make('合計伝票数', number_format($tradeCount).'件')->extraAttributes(['class' => 'text-center !py-1 !min-h-0']),
            Stat::make('合計明細数', number_format($detailCount).'行')->extraAttributes(['class' => 'text-center !py-1 !min-h-0']),
            Stat::make('配送コース数', number_format($courseCount).'コース')->extraAttributes(['class' => 'text-center !py-1 !min-h-0']),
        ];
    }
}
