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
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->unsignedBigInteger('earning_id')->nullable()->after('trade_id')->comment('売上ID');
            $table->index('earning_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropIndex(['earning_id']);
            $table->dropColumn('earning_id');
        });
    }
};
