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
        Schema::connection('sakemaru')->table('wms_order_data_files', function (Blueprint $table) {
            $table->string('mail_to', 255)->nullable()->after('fax_downloaded_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_order_data_files', function (Blueprint $table) {
            $table->dropColumn('mail_to');
        });
    }
};
