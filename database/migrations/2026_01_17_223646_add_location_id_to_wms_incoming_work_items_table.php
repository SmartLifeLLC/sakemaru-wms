<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_work_items', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('warehouse_id')->comment('入庫ロケーションID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_work_items', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
