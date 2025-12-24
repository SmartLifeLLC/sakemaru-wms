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
        Schema::connection($this->connection)->table('wms_warehouse_contractor_settings', function (Blueprint $table) {
            $table->boolean('is_auto_transmission')->default(false)->after('transmission_days')->comment('自動送信有効');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_warehouse_contractor_settings', function (Blueprint $table) {
            $table->dropColumn('is_auto_transmission');
        });
    }
};
