<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     *
     * available_quantity を生成カラムに変更
     * available_quantity = current_quantity - reserved_quantity
     * ※ picking_quantity は使用しない
     */
    public function up(): void
    {
        // 既存のavailable_quantityカラムを削除し、生成カラムとして再作成
        DB::connection($this->connection)->statement('
            ALTER TABLE real_stocks
            DROP COLUMN available_quantity
        ');

        DB::connection($this->connection)->statement('
            ALTER TABLE real_stocks
            ADD COLUMN available_quantity INT
            GENERATED ALWAYS AS (current_quantity - reserved_quantity) STORED
            AFTER reserved_quantity
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 生成カラムを削除し、通常のINTカラムとして再作成
        DB::connection($this->connection)->statement('
            ALTER TABLE real_stocks
            DROP COLUMN available_quantity
        ');

        DB::connection($this->connection)->statement('
            ALTER TABLE real_stocks
            ADD COLUMN available_quantity INT DEFAULT 0
            AFTER reserved_quantity
        ');

        // 既存データの値を再計算
        DB::connection($this->connection)->statement('
            UPDATE real_stocks
            SET available_quantity = current_quantity - reserved_quantity
        ');
    }
};
