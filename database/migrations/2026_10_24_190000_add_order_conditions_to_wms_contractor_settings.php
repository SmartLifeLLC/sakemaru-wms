<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table): void {
            $table->text('order_conditions')->nullable()->comment('発注ロット／発注条件');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('wms_contractor_settings', function (Blueprint $table): void {
            $table->dropColumn('order_conditions');
        });
    }
};
