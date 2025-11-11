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
        Schema::connection('sakemaru')->create('wms_floor_objects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('floor_id')->comment('フロアID');
            $table->enum('type', ['pillar', 'fixed_area'])->comment('オブジェクトタイプ: pillar=柱, fixed_area=固定領域');
            $table->string('name', 255)->comment('オブジェクト名（例: 柱1, エレベーター, 荷下ろし場）');
            $table->text('description')->nullable()->comment('説明');

            // Position and size
            $table->integer('x1_pos')->default(0)->comment('X座標開始位置（px）');
            $table->integer('y1_pos')->default(0)->comment('Y座標開始位置（px）');
            $table->integer('x2_pos')->default(0)->comment('X座標終了位置（px）');
            $table->integer('y2_pos')->default(0)->comment('Y座標終了位置（px）');

            $table->timestamps();

            $table->index('floor_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->dropIfExists('wms_floor_objects');
    }
};
