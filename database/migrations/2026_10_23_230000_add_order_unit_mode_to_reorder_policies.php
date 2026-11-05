<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_item_reorder_policies', function (Blueprint $table) {
            $table->string('order_unit_mode', 8)->nullable()->default('AUTO');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('発注単位の指定を保持します。自動発注をOFFにしてコードを戻してください。');
    }
};
