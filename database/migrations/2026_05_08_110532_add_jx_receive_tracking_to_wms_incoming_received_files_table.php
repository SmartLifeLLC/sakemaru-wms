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
        Schema::connection('sakemaru')->table('wms_incoming_received_files', function (Blueprint $table) {
            $table->string('raw_file_path', 512)->nullable()->after('filename');
            $table->unsignedInteger('raw_file_size')->nullable()->after('raw_file_path');
            $table->string('raw_sha256', 64)->nullable()->after('raw_file_size');
            $table->string('received_message_id', 100)->nullable()->after('raw_sha256');
            $table->string('get_request_path', 512)->nullable()->after('received_message_id');
            $table->string('get_response_path', 512)->nullable()->after('get_request_path');
            $table->string('confirm_status', 20)->nullable()->after('get_response_path');
            $table->timestamp('confirmed_at')->nullable()->after('confirm_status');
            $table->string('confirm_request_path', 512)->nullable()->after('confirmed_at');
            $table->string('confirm_response_path', 512)->nullable()->after('confirm_request_path');
            $table->text('confirm_error_message')->nullable()->after('confirm_response_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('sakemaru')->table('wms_incoming_received_files', function (Blueprint $table) {
            $table->dropColumn([
                'raw_file_path',
                'raw_file_size',
                'raw_sha256',
                'received_message_id',
                'get_request_path',
                'get_response_path',
                'confirm_status',
                'confirmed_at',
                'confirm_request_path',
                'confirm_response_path',
                'confirm_error_message',
            ]);
        });
    }
};
