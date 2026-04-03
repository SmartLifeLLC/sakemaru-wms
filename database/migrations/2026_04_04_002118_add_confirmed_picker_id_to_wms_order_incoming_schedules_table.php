<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_picker_id')->nullable()->after('confirmed_by')
                ->comment('入庫確定ピッカーID（API経由の場合）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropColumn('confirmed_picker_id');
        });
    }
};
