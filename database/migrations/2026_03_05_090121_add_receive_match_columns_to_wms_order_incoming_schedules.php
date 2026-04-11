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
        Schema::table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->boolean('is_receive_matched')->default(false)->after('status');
            $table->integer('shortage_quantity')->default(0)->after('received_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropColumn(['is_receive_matched', 'shortage_quantity']);
        });
    }
};
