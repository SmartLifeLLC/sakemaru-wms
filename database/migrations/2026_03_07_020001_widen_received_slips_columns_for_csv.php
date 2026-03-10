<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_received_slips', function (Blueprint $table) {
            $table->string('b_shop_code', 20)->change();
            $table->string('b_order_date', 20)->change();
            $table->string('b_delivery_date', 20)->change();
            $table->string('b_contractor_code', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_received_slips', function (Blueprint $table) {
            $table->string('b_shop_code', 4)->change();
            $table->string('b_order_date', 6)->change();
            $table->string('b_delivery_date', 6)->change();
            $table->string('b_contractor_code', 4)->change();
        });
    }
};
