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
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->date('expiration_date')->nullable()->after('actual_arrival_date')->comment('賞味期限');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_order_incoming_schedules', function (Blueprint $table) {
            $table->dropColumn('expiration_date');
        });
    }
};
