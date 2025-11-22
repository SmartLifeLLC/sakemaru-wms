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
        // Add delivery_course_id to wms_shortages
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_course_id')->nullable()->after('trade_id')->comment('配送コースID');
            $table->index('delivery_course_id');
        });

        // Add delivery_course_id to wms_shortage_allocations
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_course_id')->nullable()->after('shortage_id')->comment('配送コースID');
            $table->index('delivery_course_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropIndex(['delivery_course_id']);
            $table->dropColumn('delivery_course_id');
        });

        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropIndex(['delivery_course_id']);
            $table->dropColumn('delivery_course_id');
        });
    }
};
