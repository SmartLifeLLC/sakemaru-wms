<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_count_round')->default(1)->after('status');
            $table->timestamp('first_count_confirmed_at')->nullable()->after('confirmed_by');
            $table->unsignedBigInteger('first_count_confirmed_by')->nullable()->after('first_count_confirmed_at');
            $table->timestamp('second_count_confirmed_at')->nullable()->after('first_count_confirmed_by');
            $table->unsignedBigInteger('second_count_confirmed_by')->nullable()->after('second_count_confirmed_at');
            $table->timestamp('final_count_confirmed_at')->nullable()->after('second_count_confirmed_by');
            $table->unsignedBigInteger('final_count_confirmed_by')->nullable()->after('final_count_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_inventory_counts', function (Blueprint $table) {
            $table->dropColumn([
                'current_count_round',
                'first_count_confirmed_at',
                'first_count_confirmed_by',
                'second_count_confirmed_at',
                'second_count_confirmed_by',
                'final_count_confirmed_at',
                'final_count_confirmed_by',
            ]);
        });
    }
};
