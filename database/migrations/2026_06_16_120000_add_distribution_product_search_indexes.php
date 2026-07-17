<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ITEM_SEARCH_INDEX = 'idx_isi_client_code_search_item';

    private const ITEM_CONTRACTOR_INDEX = 'idx_ic_client_contractor_item';

    public function up(): void
    {
        if (! $this->hasIndex('item_search_information', self::ITEM_SEARCH_INDEX)) {
            Schema::connection('sakemaru')->table('item_search_information', function (Blueprint $table) {
                $table->index(['client_id', 'code_type', 'search_string', 'item_id'], self::ITEM_SEARCH_INDEX);
            });
        }

        if (! $this->hasIndex('item_contractors', self::ITEM_CONTRACTOR_INDEX)) {
            Schema::connection('sakemaru')->table('item_contractors', function (Blueprint $table) {
                $table->index(['client_id', 'contractor_id', 'item_id'], self::ITEM_CONTRACTOR_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('item_contractors', self::ITEM_CONTRACTOR_INDEX)) {
            Schema::connection('sakemaru')->table('item_contractors', function (Blueprint $table) {
                $table->dropIndex(self::ITEM_CONTRACTOR_INDEX);
            });
        }

        if ($this->hasIndex('item_search_information', self::ITEM_SEARCH_INDEX)) {
            Schema::connection('sakemaru')->table('item_search_information', function (Blueprint $table) {
                $table->dropIndex(self::ITEM_SEARCH_INDEX);
            });
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(DB::connection('sakemaru')->select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]))
            ->isNotEmpty();
    }
};
