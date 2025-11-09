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
        Schema::connection('sakemaru')->create('wms_location_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id')->comment('基幹システムのlocation ID');
            $table->integer('level_number')->comment('段番号 (1~)');
            $table->string('name', 255)->nullable()->comment('段の名称');
            $table->integer('available_quantity_flags')->default(0)->comment('引当可能単位フラグ');
            $table->timestamps();

            // Indexes
            $table->index('location_id');
            $table->unique(['location_id', 'level_number'], 'wms_location_levels_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_location_levels');
    }
};
