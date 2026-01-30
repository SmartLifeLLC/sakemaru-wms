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
        Schema::connection('sakemaru')->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->date('order_date')->nullable()->after('contractor_id')->comment('発注日');
            $table->date('expected_arrival_date')->nullable()->after('order_date')->comment('入荷予定日');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_documents', function (Blueprint $table) {
            $table->dropColumn(['order_date', 'expected_arrival_date']);
        });
    }
};
