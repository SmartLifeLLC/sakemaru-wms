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
        if(Schema::connection('sakemaru')->hasTable('real_stocks')){
            Schema::connection('sakemaru')->table('real_stocks', function (Blueprint $table) {
                $table->integer('wms_reserved_qty')->default(0)->comment('WMS reserved quantity')->after('available_quantity');
                $table->integer('wms_picking_qty')->default(0)->comment('Currently being picked quantity')->after('wms_reserved_qty');
                $table->integer('wms_lock_version')->default(0)->comment('Optimistic lock version')->after('wms_picking_qty');
            });
        }
    
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('real_stocks', function (Blueprint $table) {
            $table->dropColumn(['wms_reserved_qty', 'wms_picking_qty', 'wms_lock_version']);
        });
    }
};
