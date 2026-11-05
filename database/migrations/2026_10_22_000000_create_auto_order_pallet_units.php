<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        // Additive only: do not ALTER/backfill the large shared item or policy tables.
        $schema = Schema::connection($this->connection);
        $schema->create('wms_pallet_units', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('source_warehouse_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedInteger('pieces_per_pallet');
            $t->unsignedInteger('capacity_case');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
            $t->unique(['client_id', 'source_warehouse_id', 'item_id'], 'wms_pallet_unit_scope');
        });
        $schema->create('wms_policy_pallet_units', function (Blueprint $t) {
            $t->unsignedBigInteger('policy_id')->primary();
            $t->unsignedBigInteger('pallet_unit_id')->index();
            $t->unsignedInteger('pallet_version');
            $t->unsignedInteger('pieces_per_pallet');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('パレット設定と履歴は保持してください。コードを戻す場合もテーブルは削除しません。');
    }
};
