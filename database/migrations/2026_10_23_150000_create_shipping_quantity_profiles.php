<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        $schema = Schema::connection('sakemaru');
        $schema->create('wms_shipping_quantity_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('source_warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->json('quantities');
            $t->unsignedInteger('capacity_case');
            $t->json('basis')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
            $t->unique(['client_id', 'source_warehouse_id', 'item_id'], 'wms_shipping_quantity_scope');
        });
        $schema->create('wms_policy_shipping_quantities', function (Blueprint $t) {
            $t->unsignedBigInteger('policy_id')->primary();
            $t->unsignedBigInteger('profile_id')->index();
            $t->unsignedInteger('profile_version');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('出荷数量候補と設定の根拠は保持してください。コードを戻す場合もテーブルは削除しません。');
    }
};
