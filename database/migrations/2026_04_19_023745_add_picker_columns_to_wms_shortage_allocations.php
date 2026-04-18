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
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('is_finished');
            $table->unsignedBigInteger('started_picker_id')->nullable()->after('started_at');
            $table->unsignedBigInteger('finished_picker_id')->nullable()->after('started_picker_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortage_allocations', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'started_picker_id', 'finished_picker_id']);
        });
    }
};
