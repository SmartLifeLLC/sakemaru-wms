<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wms_contractor_settings', function (Blueprint $table) {
            $table->string('order_mail', 255)->nullable()->after('is_auto_transmission')->comment('発注メールアドレス');
            $table->string('order_mail_from', 100)->nullable()->after('order_mail')->comment('送信名');
            $table->string('order_mail_title', 200)->nullable()->after('order_mail_from')->comment('メールタイトル');
            $table->text('order_mail_content')->nullable()->after('order_mail_title')->comment('メール本文テンプレート');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wms_contractor_settings', function (Blueprint $table) {
            $table->dropColumn(['order_mail', 'order_mail_from', 'order_mail_title', 'order_mail_content']);
        });
    }
};
