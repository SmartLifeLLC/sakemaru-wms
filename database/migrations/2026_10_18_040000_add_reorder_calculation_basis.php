<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_item_reorder_policies', function (Blueprint $table) {
            $table->json('calculation_basis')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('算定根拠は監査用に保持します。機能を無効化してください。');
    }
};
