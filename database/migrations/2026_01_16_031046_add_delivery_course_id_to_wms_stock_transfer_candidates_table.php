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
        Schema::connection($this->connection)->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_course_id')->nullable()->after('contractor_id')
                ->comment('配送コースID（ピッキングリスト用）');

            $table->index('delivery_course_id', 'idx_delivery_course_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_stock_transfer_candidates', function (Blueprint $table) {
            $table->dropIndex('idx_delivery_course_id');
            $table->dropColumn('delivery_course_id');
        });
    }
};
