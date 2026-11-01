<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait ProxyShipmentDatabase
{
    protected function createProxyShipmentDatabase(string $connection): void
    {
        config(['database.default' => $connection, 'database.connections.'.$connection => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ], 'app.client_id' => 6, 'client.id' => 6]);
        DB::purge($connection);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('trades', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->integer('serial_id');
            $t->integer('quantity')->default(10);
            $t->string('note')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_latest')->default(true);
            $t->timestamps();
        });
        Schema::create('trade_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('trade_id');
            $t->integer('quantity');
            $t->string('quantity_type')->default('PIECE');
            $t->timestamps();
        });
        Schema::create('wms_shortages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('trade_id');
            $t->unsignedBigInteger('trade_item_id')->nullable();
            $t->unsignedBigInteger('source_pick_result_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->default(1);
            $t->unsignedBigInteger('item_id')->default(1);
            $t->unsignedBigInteger('earning_id')->nullable();
            $t->integer('order_qty')->default(10);
            $t->integer('picked_qty')->default(4);
            $t->integer('shortage_qty')->default(6);
            $t->string('qty_type_at_order')->default('PIECE');
            $t->string('status')->default('REALLOCATING');
            $t->timestamps();
        });
        Schema::create('wms_shortage_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shortage_id');
            $t->unsignedBigInteger('target_warehouse_id')->default(2);
            $t->unsignedBigInteger('source_warehouse_id')->default(1);
            $t->date('shipment_date')->nullable();
            $t->unsignedBigInteger('delivery_course_id')->nullable();
            $t->integer('assign_qty')->default(6);
            $t->integer('picked_qty')->default(2);
            $t->string('assign_qty_type')->default('PIECE');
            $t->string('status')->default('RESERVED');
            $t->boolean('is_finished')->default(false);
            $t->boolean('is_confirmed')->default(true);
            $t->boolean('is_synced')->default(false);
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('confirmed_user_id')->nullable();
            $t->timestamp('is_synced_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->unsignedBigInteger('finished_user_id')->nullable();
            $t->unsignedBigInteger('finished_picker_id')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->unsignedBigInteger('started_picker_id')->nullable();
            $t->decimal('purchase_price')->default(1);
            $t->timestamps();
        });
        Schema::create('stock_transfer_queue', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->string('request_id')->unique();
            $t->string('action_type')->default('CREATE');
            $t->string('status')->default('BEFORE');
            $t->boolean('is_success')->nullable();
            $t->unsignedBigInteger('stock_transfer_id')->nullable();
            $t->text('items')->default('[]');
            $t->string('from_warehouse_code')->nullable();
            $t->string('to_warehouse_code')->nullable();
            $t->string('slip_number')->nullable();
            $t->date('process_date')->nullable();
            $t->date('delivered_date')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('trade_id');
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('from_warehouse_id')->default(2);
            $t->unsignedBigInteger('to_warehouse_id')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('quantity_update_queue', function (Blueprint $t) {
            $t->id();
            $t->string('request_id')->unique();
        });
        Schema::create('real_stocks', function (Blueprint $t) {
            $t->id();
            $t->integer('current_quantity');
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->unsignedBigInteger('client_id')->default(6);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_virtual')->default(false);
        });
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->boolean('is_active')->default(true);
        });
        Schema::create('log_pdf_exports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('creator_id');
            $t->string('type');
            $t->string('name');
            $t->string('path');
            $t->timestamp('prepared_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->timestamp('exported_at')->nullable();
            $t->integer('exported_count')->default(0);
            $t->timestamps();
        });
        Schema::create('pdf_exportables', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('log_pdf_export_id');
            $t->string('pdf_exportable_type');
            $t->unsignedBigInteger('pdf_exportable_id');
        });
        $migration = require __DIR__.'/../../database/migrations/2026_11_01_150000_add_proxy_shipment_cancellation_and_print_requests.php';
        // The migration only creates new columns/tables in this fresh in-memory fixture.
        $migration->up();
        DB::table('trades')->insert([['id' => 10, 'client_id' => 6, 'serial_id' => 1000], ['id' => 20, 'client_id' => 6, 'serial_id' => 2000]]);
        DB::table('trade_items')->insert(['id' => 1, 'trade_id' => 10, 'quantity' => 10]);
        DB::table('wms_shortages')->insert(['id' => 1, 'trade_id' => 10, 'trade_item_id' => 1]);
        DB::table('wms_shortage_allocations')->insert(['id' => 1, 'shortage_id' => 1]);
        DB::table('warehouses')->insert([['id' => 1, 'code' => '1', 'name' => '元倉庫'], ['id' => 2, 'code' => '2', 'name' => '出荷倉庫']]);
        DB::table('items')->insert(['id' => 1, 'code' => '100', 'name' => '商品']);
        DB::table('real_stocks')->insert(['id' => 1, 'current_quantity' => 30]);
    }

    protected function makePrintable(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['is_finished' => true, 'status' => 'FULFILLED', 'picked_qty' => 6]);
        DB::table('stock_transfers')->insert(['id' => 50, 'trade_id' => 20, 'client_id' => 6]);
        DB::table('stock_transfer_queue')->insert(['id' => 1, 'client_id' => 6, 'request_id' => 'proxy-shipment-1', 'stock_transfer_id' => 50, 'status' => 'FINISHED', 'is_success' => true]);
    }
}
