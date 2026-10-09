<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ITEM_SEARCH_INDEX = 'idx_isi_client_code_search_item';

    private const ITEM_CONTRACTOR_INDEX = 'idx_ic_client_contractor_item';

    public function up(): void
    {
        if (! $this->hasIndex('item_search_information', self::ITEM_SEARCH_INDEX)) {
            DB::connection('sakemaru')->statement(
                'ALTER TABLE item_search_information ADD INDEX '.self::ITEM_SEARCH_INDEX
                .' (client_id, code_type, search_string, item_id), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }

        if (! $this->hasIndex('item_contractors', self::ITEM_CONTRACTOR_INDEX)) {
            DB::connection('sakemaru')->statement(
                'ALTER TABLE item_contractors ADD INDEX '.self::ITEM_CONTRACTOR_INDEX
                .' (client_id, contractor_id, item_id), ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('item_contractors', self::ITEM_CONTRACTOR_INDEX)) {
            DB::connection('sakemaru')->statement(
                'ALTER TABLE item_contractors DROP INDEX '.self::ITEM_CONTRACTOR_INDEX
                .', ALGORITHM=INPLACE, LOCK=NONE'
            );
        }

        if ($this->hasIndex('item_search_information', self::ITEM_SEARCH_INDEX)) {
            DB::connection('sakemaru')->statement(
                'ALTER TABLE item_search_information DROP INDEX '.self::ITEM_SEARCH_INDEX
                .', ALGORITHM=INPLACE, LOCK=NONE'
            );
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return collect(DB::connection('sakemaru')->select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]))
            ->isNotEmpty();
    }
};
