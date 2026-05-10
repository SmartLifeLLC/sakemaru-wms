<?php

namespace App\Filament\Resources\WmsStockTransferConfirmed;

use App\Enums\EMenu;
use App\Filament\Resources\WmsStockTransferConfirmed\Pages\ListWmsStockTransferConfirmed;
use App\Filament\Resources\WmsStockTransferConfirmed\Tables\WmsStockTransferConfirmedTable;
use App\Filament\Support\AdminResource;
use App\Models\Sakemaru\StockTransferHistory;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WmsStockTransferConfirmedResource extends AdminResource
{
    protected static ?string $model = StockTransferHistory::class;

    protected static string $permissionResource = 'wms-stock-transfer-candidate';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $slug = 'wms-stock-transfer-confirmed';

    public static function getNavigationGroup(): ?string
    {
        return EMenu::WMS_STOCK_TRANSFER_CONFIRMED->category()->label();
    }

    public static function getNavigationLabel(): string
    {
        return EMenu::WMS_STOCK_TRANSFER_CONFIRMED->label();
    }

    public static function getModelLabel(): string
    {
        return '移動確定済み';
    }

    public static function getPluralModelLabel(): string
    {
        return '移動確定済み';
    }

    public static function getNavigationSort(): ?int
    {
        return EMenu::WMS_STOCK_TRANSFER_CONFIRMED->sort();
    }

    public static function getEloquentQuery(): Builder
    {
        $model = new StockTransferHistory;

        return $model->newQuery()
            ->fromSub(static::historyQuery(), 'stock_transfer_histories')
            ->select('stock_transfer_histories.*');
    }

    public static function table(Table $table): Table
    {
        return WmsStockTransferConfirmedTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWmsStockTransferConfirmed::route('/'),
        ];
    }

    private static function historyQuery()
    {
        $connection = DB::connection('sakemaru');
        $latestCreateQueues = $connection->table('stock_transfer_queue')
            ->selectRaw('MAX(id) as id, stock_transfer_id')
            ->where('action_type', 'CREATE')
            ->whereNotNull('stock_transfer_id')
            ->groupBy('stock_transfer_id');
        $latestJobControls = $connection->table('wms_auto_order_job_controls')
            ->selectRaw('MAX(id) as id, batch_code')
            ->whereNotNull('batch_code')
            ->whereNotNull('created_by')
            ->groupBy('batch_code');

        $transferRows = $connection->table('stock_transfers as st')
            ->join('trades as t', 't.id', '=', 'st.trade_id')
            ->leftJoinSub($latestCreateQueues, 'latest_stq', fn ($join) => $join->on('latest_stq.stock_transfer_id', '=', 'st.id'))
            ->leftJoin('stock_transfer_queue as stq', 'stq.id', '=', 'latest_stq.id')
            ->leftJoinSub($latestJobControls, 'latest_job', fn ($join) => $join->whereRaw("latest_job.batch_code COLLATE utf8mb4_unicode_ci = REGEXP_SUBSTR(COALESCE(stq.note, t.note), '[0-9]{14,17}') COLLATE utf8mb4_unicode_ci"))
            ->leftJoin('wms_auto_order_job_controls as job', 'job.id', '=', 'latest_job.id')
            ->leftJoin('users as creator', 'creator.id', '=', 'job.created_by')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 'st.from_warehouse_id')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 'st.to_warehouse_id')
            ->leftJoin('delivery_courses as dc', 'dc.id', '=', 'st.delivery_course_id')
            ->where('st.is_active', true)
            ->selectRaw(<<<'SQL'
                st.id as id,
                'transfer' as source_type,
                st.id as stock_transfer_id,
                stq.id as queue_id,
                REGEXP_SUBSTR(COALESCE(stq.note, t.note), '[0-9]{14,17}') as batch_code,
                t.serial_id as transfer_number,
                st.trade_id as trade_id,
                st.from_warehouse_id as from_warehouse_id,
                st.to_warehouse_id as to_warehouse_id,
                st.delivery_course_id as delivery_course_id,
                t.process_date as process_date,
                st.delivered_date as delivered_date,
                st.picking_status as picking_status,
                stq.status as queue_status,
                COALESCE(stq.note, t.note) as note,
                job.created_by as candidate_creator_id,
                creator.name as candidate_creator_name,
                fw.code as from_warehouse_code,
                fw.name as from_warehouse_name,
                tw.code as to_warehouse_code,
                tw.name as to_warehouse_name,
                dc.name as delivery_course_name,
                (
                    select count(*)
                    from trade_items ti
                    where ti.trade_id = st.trade_id
                ) as item_count,
                stq.error_message as error_message,
                COALESCE(stq.created_at, st.created_at) as created_at,
                COALESCE(stq.updated_at, st.updated_at) as updated_at
            SQL);

        $queueRows = $connection->table('stock_transfer_queue as stq')
            ->leftJoinSub($latestJobControls, 'latest_job', fn ($join) => $join->whereRaw("latest_job.batch_code COLLATE utf8mb4_unicode_ci = REGEXP_SUBSTR(stq.note, '[0-9]{14,17}') COLLATE utf8mb4_unicode_ci"))
            ->leftJoin('wms_auto_order_job_controls as job', 'job.id', '=', 'latest_job.id')
            ->leftJoin('users as creator', 'creator.id', '=', 'job.created_by')
            ->leftJoin('warehouses as fw', 'fw.code', '=', 'stq.from_warehouse_code')
            ->leftJoin('warehouses as tw', 'tw.code', '=', 'stq.to_warehouse_code')
            ->leftJoin('delivery_courses as dc', 'dc.id', '=', 'stq.delivery_course_id')
            ->where('stq.action_type', 'CREATE')
            ->whereNull('stq.stock_transfer_id')
            ->selectRaw(<<<'SQL'
                1000000000000 + stq.id as id,
                'queue' as source_type,
                null as stock_transfer_id,
                stq.id as queue_id,
                REGEXP_SUBSTR(stq.note, '[0-9]{14,17}') as batch_code,
                null as transfer_number,
                null as trade_id,
                fw.id as from_warehouse_id,
                tw.id as to_warehouse_id,
                stq.delivery_course_id as delivery_course_id,
                stq.process_date as process_date,
                stq.delivered_date as delivered_date,
                null as picking_status,
                stq.status as queue_status,
                stq.note as note,
                job.created_by as candidate_creator_id,
                creator.name as candidate_creator_name,
                stq.from_warehouse_code as from_warehouse_code,
                fw.name as from_warehouse_name,
                stq.to_warehouse_code as to_warehouse_code,
                tw.name as to_warehouse_name,
                dc.name as delivery_course_name,
                COALESCE(JSON_LENGTH(stq.items), 0) as item_count,
                stq.error_message as error_message,
                stq.created_at as created_at,
                stq.updated_at as updated_at
            SQL);

        return $transferRows->unionAll($queueRows);
    }
}
