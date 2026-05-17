<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('wms_order_jx_documents', 'transmitted_by_name')) {
            Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
                $table->string('transmitted_by_name')->nullable()->after('transmitted_by')->comment('送信者名');
            });
        }

        if (! Schema::connection($this->connection)->hasColumn('wms_order_jx_documents', 'created_by')) {
            Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable()->after('transmitted_by_name')->comment('作成者ID');
            });
        }

        if (! Schema::connection($this->connection)->hasColumn('wms_order_jx_documents', 'created_by_name')) {
            Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
                $table->string('created_by_name')->nullable()->after('created_by')->comment('作成者名');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('wms_order_jx_documents', 'created_by_name')) {
            Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
                $table->dropColumn('created_by_name');
            });
        }

        if (Schema::connection($this->connection)->hasColumn('wms_order_jx_documents', 'created_by')) {
            Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
                $table->dropColumn('created_by');
            });
        }

        if (Schema::connection($this->connection)->hasColumn('wms_order_jx_documents', 'transmitted_by_name')) {
            Schema::connection($this->connection)->table('wms_order_jx_documents', function (Blueprint $table) {
                $table->dropColumn('transmitted_by_name');
            });
        }
    }
};
