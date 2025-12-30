<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * Add delivery_course_id to wms_picking_tasks for direct grouping by delivery course.
     * This allows tasks to be grouped by delivery course without needing to join through
     * wave settings or item results.
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_course_id')
                ->after('warehouse_id')
                ->nullable()
                ->comment('配送コースID (delivery_courses.id)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_picking_tasks', function (Blueprint $table) {
            $table->dropColumn('delivery_course_id');
        });
    }
};
