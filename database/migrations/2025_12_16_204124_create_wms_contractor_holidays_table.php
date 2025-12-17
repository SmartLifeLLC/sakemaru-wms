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
        if (Schema::connection($this->connection)->hasTable('wms_contractor_holidays')) {
            return;
        }

        Schema::connection($this->connection)->create('wms_contractor_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contractor_id')->comment('発注先ID');
            $table->date('holiday_date')->comment('休業日');
            $table->string('reason', 100)->nullable()->comment('休業理由');
            $table->timestamps();

            $table->unique(['contractor_id', 'holiday_date'], 'wms_ch_contractor_date_unique');
            $table->index(['contractor_id', 'holiday_date'], 'wms_ch_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('wms_contractor_holidays');
    }
};
