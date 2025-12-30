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
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->string('send_document_type', 2)->default('91')->after('receiver_station_code')->comment('送信種別コード');
            $table->string('receive_document_type', 2)->default('90')->after('send_document_type')->comment('受信種別コード');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_jx_settings', function (Blueprint $table) {
            $table->dropColumn(['send_document_type', 'receive_document_type']);
        });
    }
};
