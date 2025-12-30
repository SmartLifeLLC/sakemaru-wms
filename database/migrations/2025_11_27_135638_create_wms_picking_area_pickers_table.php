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
        Schema::connection('sakemaru')->create('wms_picking_area_pickers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wms_picking_area_id')->comment('ピッキングエリアID');
            $table->unsignedBigInteger('wms_picker_id')->comment('ピッカーID');
            $table->timestamps();

            $table->unique(['wms_picking_area_id', 'wms_picker_id'], 'area_picker_unique');
            $table->index('wms_picker_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_picking_area_pickers');
    }
};
