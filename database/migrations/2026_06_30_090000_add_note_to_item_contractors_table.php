<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::connection('sakemaru')->hasTable('item_contractors') ||
            Schema::connection('sakemaru')->hasColumn('item_contractors', 'note')
        ) {
            return;
        }

        Schema::connection('sakemaru')->table('item_contractors', function (Blueprint $table): void {
            $table->text('note')
                ->nullable()
                ->after('auto_order_quantity')
                ->comment('備考');
        });
    }

    public function down(): void
    {
        if (
            ! Schema::connection('sakemaru')->hasTable('item_contractors') ||
            ! Schema::connection('sakemaru')->hasColumn('item_contractors', 'note')
        ) {
            return;
        }

        Schema::connection('sakemaru')->table('item_contractors', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
