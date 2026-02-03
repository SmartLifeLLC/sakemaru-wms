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
        Schema::connection('sakemaru')->dropIfExists('wms_location_levels');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->create('wms_location_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->integer('level_number')->default(1);
            $table->string('name')->nullable();
            $table->integer('available_quantity_flags')->default(3);
            $table->timestamps();

            $table->index('location_id');
            $table->unique(['location_id', 'level_number']);
        });
    }
};
