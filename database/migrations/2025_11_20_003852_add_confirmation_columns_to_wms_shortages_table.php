<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 承認関連カラムの追加:
     * - is_confirmed: 承認済みフラグ
     * - confirmed_by: 承認者ID
     * - confirmed_at: 承認日時（既存）
     */
    public function up(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            // is_confirmedカラムを追加（ステータスカラムの前に配置）
            $table->boolean('is_confirmed')
                ->default(false)
                ->after('case_size_snap')
                ->comment('承認済みフラグ');

            // confirmed_byカラムを追加
            $table->unsignedBigInteger('confirmed_by')
                ->nullable()
                ->after('is_confirmed')
                ->comment('承認者ID');
        });

        // confirmed_user_idからconfirmed_byにデータをコピー（既存データがある場合）
        if (Schema::connection('sakemaru')->hasColumn('wms_shortages', 'confirmed_user_id')) {
            \DB::connection('sakemaru')->statement('
                UPDATE wms_shortages
                SET confirmed_by = confirmed_user_id,
                    is_confirmed = CASE WHEN confirmed_user_id IS NOT NULL THEN 1 ELSE 0 END
                WHERE confirmed_user_id IS NOT NULL
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_shortages', function (Blueprint $table) {
            $table->dropColumn(['is_confirmed', 'confirmed_by']);
        });
    }
};
