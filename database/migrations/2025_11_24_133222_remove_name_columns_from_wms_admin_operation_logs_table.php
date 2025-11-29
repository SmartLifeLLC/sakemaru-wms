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
        $columnsToRemove = [
            'user_name',
            'user_email',
            'picker_name_before',
            'picker_name_after',
            'delivery_course_name_before',
            'delivery_course_name_after',
            'warehouse_name_before',
            'warehouse_name_after',
        ];

        $existingColumns = Schema::connection('sakemaru')->getColumnListing('wms_admin_operation_logs');
        $columnsToDrop = array_intersect($columnsToRemove, $existingColumns);

        if (! empty($columnsToDrop)) {
            Schema::connection('sakemaru')->table('wms_admin_operation_logs', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_admin_operation_logs', function (Blueprint $table) {
            $table->string('user_name', 100)->nullable()->comment('管理者ユーザー名');
            $table->string('user_email', 255)->nullable()->comment('管理者メールアドレス');
            $table->string('picker_name_before', 100)->nullable()->comment('ピッカー名（変更前）');
            $table->string('picker_name_after', 100)->nullable()->comment('ピッカー名（変更後）');
            $table->string('delivery_course_name_before', 255)->nullable()->comment('配送コース名（変更前）');
            $table->string('delivery_course_name_after', 255)->nullable()->comment('配送コース名（変更後）');
            $table->string('warehouse_name_before', 255)->nullable()->comment('倉庫名（変更前）');
            $table->string('warehouse_name_after', 255)->nullable()->comment('倉庫名（変更後）');
        });
    }
};
