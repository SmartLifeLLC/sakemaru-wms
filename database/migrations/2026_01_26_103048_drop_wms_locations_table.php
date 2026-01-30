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
     * wms_locations テーブルは不要になったため削除する。
     * locations.wms_picking_area_id を直接使用するように統一した。
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_locations');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->create('wms_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id')->index();
            $table->unsignedBigInteger('wms_picking_area_id')->nullable()->index();
            $table->string('picking_unit_type')->nullable();
            $table->integer('walking_order')->nullable();
            $table->string('aisle')->nullable();
            $table->string('rack')->nullable();
            $table->string('level')->nullable();
            $table->timestamps();
        });
    }
};
