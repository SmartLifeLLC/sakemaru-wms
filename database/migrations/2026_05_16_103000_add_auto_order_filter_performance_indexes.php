<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const JOB_CREATED_BATCH_INDEX = 'idx_wms_auto_order_created_batch';

    private const JOB_PENDING_CANCEL_INDEX = 'idx_wms_auto_order_pending_cancel';

    private const ORDER_STATUS_BATCH_WAREHOUSE_ITEM_CONTRACTOR_INDEX = 'idx_wms_order_status_batch_wh_item_contractor';

    private const TRANSFER_STATUS_BATCH_SAT_ITEM_CONTRACTOR_INDEX = 'idx_wms_transfer_status_batch_sat_item_contractor';

    public function up(): void
    {
        if (! $this->indexExists('wms_auto_order_job_controls', self::JOB_CREATED_BATCH_INDEX)) {
            Schema::connection($this->connection)->table('wms_auto_order_job_controls', function (Blueprint $table) {
                $table->index(['created_by', 'batch_code'], self::JOB_CREATED_BATCH_INDEX);
            });
        }

        if (! $this->indexExists('wms_auto_order_job_controls', self::JOB_PENDING_CANCEL_INDEX)) {
            Schema::connection($this->connection)->table('wms_auto_order_job_controls', function (Blueprint $table) {
                $table->index(['settlement_status', 'created_by', 'process_name', 'warehouse_id'], self::JOB_PENDING_CANCEL_INDEX);
            });
        }

        if (! $this->indexExists('wms_order_candidates', self::ORDER_STATUS_BATCH_WAREHOUSE_ITEM_CONTRACTOR_INDEX)) {
            Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
                $table->index(['status', 'batch_code', 'warehouse_id', 'item_id', 'contractor_id', 'supplier_id'], self::ORDER_STATUS_BATCH_WAREHOUSE_ITEM_CONTRACTOR_INDEX);
            });
        }

        if (! $this->indexExists('wms_stock_transfer_candidates', self::TRANSFER_STATUS_BATCH_SAT_ITEM_CONTRACTOR_INDEX)) {
            Schema::connection($this->connection)->table('wms_stock_transfer_candidates', function (Blueprint $table) {
                $table->index(['status', 'batch_code', 'satellite_warehouse_id', 'hub_warehouse_id', 'item_id', 'contractor_id'], self::TRANSFER_STATUS_BATCH_SAT_ITEM_CONTRACTOR_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('wms_stock_transfer_candidates', self::TRANSFER_STATUS_BATCH_SAT_ITEM_CONTRACTOR_INDEX)) {
            Schema::connection($this->connection)->table('wms_stock_transfer_candidates', function (Blueprint $table) {
                $table->dropIndex(self::TRANSFER_STATUS_BATCH_SAT_ITEM_CONTRACTOR_INDEX);
            });
        }

        if ($this->indexExists('wms_order_candidates', self::ORDER_STATUS_BATCH_WAREHOUSE_ITEM_CONTRACTOR_INDEX)) {
            Schema::connection($this->connection)->table('wms_order_candidates', function (Blueprint $table) {
                $table->dropIndex(self::ORDER_STATUS_BATCH_WAREHOUSE_ITEM_CONTRACTOR_INDEX);
            });
        }

        if ($this->indexExists('wms_auto_order_job_controls', self::JOB_PENDING_CANCEL_INDEX)) {
            Schema::connection($this->connection)->table('wms_auto_order_job_controls', function (Blueprint $table) {
                $table->dropIndex(self::JOB_PENDING_CANCEL_INDEX);
            });
        }

        if ($this->indexExists('wms_auto_order_job_controls', self::JOB_CREATED_BATCH_INDEX)) {
            Schema::connection($this->connection)->table('wms_auto_order_job_controls', function (Blueprint $table) {
                $table->dropIndex(self::JOB_CREATED_BATCH_INDEX);
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
