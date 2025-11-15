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
        // Connection for WMS tables
        $connection = 'sakemaru';

        // Navigation nodes table
        Schema::connection($connection)->create('wms_picking_nav_nodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->integer('x')->comment('X coordinate in pixels');
            $table->integer('y')->comment('Y coordinate in pixels');
            $table->enum('kind', ['GRID', 'PORTAL', 'START'])->default('GRID');
            $table->timestamps();

            $table->unique(['warehouse_id', 'floor_id', 'x', 'y'], 'uk_navnode_xy');
            $table->index(['warehouse_id', 'floor_id']);
        });

        // Navigation edges table
        Schema::connection($connection)->create('wms_picking_nav_edges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->unsignedBigInteger('node_u')->comment('From node ID');
            $table->unsignedBigInteger('node_v')->comment('To node ID');
            $table->integer('length')->comment('Edge length in pixels');
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();

            $table->index(['warehouse_id', 'floor_id', 'node_u', 'node_v'], 'uk_edge');
            $table->index(['node_u', 'node_v'], 'idx_edge_uv');
        });

        // Distance cache table
        Schema::connection($connection)->create('wms_layout_distance_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('floor_id')->nullable();
            $table->char('layout_hash', 32)->comment('MD5 hash of walls/fixed_areas/width/height');
            $table->string('from_key', 64)->comment('LOC:{id} or NODE:{id}');
            $table->string('to_key', 64)->comment('LOC:{id} or NODE:{id}');
            $table->integer('meters')->comment('Distance in pixels');
            $table->json('path_json')->nullable()->comment('Path coordinates for visualization');
            $table->timestamps();

            $table->unique(['warehouse_id', 'floor_id', 'layout_hash', 'from_key', 'to_key'], 'uk_dist');
            $table->index(['warehouse_id', 'floor_id', 'layout_hash'], 'idx_layout_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = 'sakemaru';

        Schema::connection($connection)->dropIfExists('wms_layout_distance_cache');
        Schema::connection($connection)->dropIfExists('wms_picking_nav_edges');
        Schema::connection($connection)->dropIfExists('wms_picking_nav_nodes');
    }
};
