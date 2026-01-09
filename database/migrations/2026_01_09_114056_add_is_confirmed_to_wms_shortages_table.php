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
            $table->boolean('is_confirmed')->default(false)->after('case_size_snap')
                ->comment('承認済みフラグ');
            $table->unsignedBigInteger('confirmed_by')->nullable()->after('is_confirmed')
                ->comment('承認者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropColumn(['is_confirmed', 'confirmed_by']);
        });
    }
};
