<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->create('wms_hana_origin_locations', function (Blueprint $table) {
            $table->id();
            $table->string('oracle_store_code', 32)->comment('Oracle 店舗CD trimmed');
            $table->string('oracle_store_code_raw', 64)->nullable()->comment('Oracle 店舗CD original value');
            $table->string('oracle_product_code', 64)->comment('Oracle 商品CD trimmed');
            $table->string('oracle_product_code_raw', 128)->nullable()->comment('Oracle 商品CD original value');
            $table->string('oracle_item_code', 64)->nullable()->comment('Oracle 単品CD trimmed');
            $table->string('oracle_item_code_raw', 128)->nullable()->comment('Oracle 単品CD original value');
            $table->string('oracle_shelf_code', 255)->nullable()->comment('Oracle 棚番 trimmed');
            $table->string('oracle_shelf_code_raw', 255)->nullable()->comment('Oracle 棚番 original value');
            $table->string('sales_flag', 16)->nullable()->comment('Oracle 販売F');
            $table->string('main_vendor_code', 64)->nullable()->comment('Oracle 店舗主仕入先CD');
            $table->dateTime('oracle_updated_at')->nullable()->comment('Oracle 変更日');
            $table->dateTime('last_purchase_date')->nullable()->comment('Oracle 最終仕入日');
            $table->unsignedBigInteger('warehouse_id')->nullable()->comment('Resolved MySQL warehouses.id');
            $table->unsignedBigInteger('item_id')->nullable()->comment('Resolved MySQL items.id by 単品CD');
            $table->timestamp('synced_at')->nullable()->comment('Data transfer timestamp');
            $table->timestamps();

            $table->index(['oracle_store_code', 'oracle_item_code'], 'idx_wms_hol_store_item');
            $table->index(['oracle_store_code', 'oracle_product_code'], 'idx_wms_hol_store_product');
            $table->index(['warehouse_id', 'item_id'], 'idx_wms_hol_warehouse_item');
            $table->index('oracle_shelf_code', 'idx_wms_hol_shelf');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_hana_origin_locations');
    }
};
