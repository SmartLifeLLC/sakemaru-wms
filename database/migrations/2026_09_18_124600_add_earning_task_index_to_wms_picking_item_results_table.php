<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'sakemaru';

    private const TABLE = 'wms_picking_item_results';

    private const INDEX = 'idx_wpir_earning_task';

    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        // Cover the earning-to-task lookup performed while completing picking tasks.
        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE `%s` ADD INDEX `%s` (`earning_id`, `picking_task_id`), ALGORITHM=INPLACE, LOCK=NONE',
            self::TABLE,
            self::INDEX,
        ));
    }

    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        DB::connection($this->connection)->statement(sprintf(
            'ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE',
            self::TABLE,
            self::INDEX,
        ));
    }

    private function indexExists(): bool
    {
        $database = config("database.connections.{$this->connection}.database");

        return DB::connection($this->connection)
            ->table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', self::TABLE)
            ->where('index_name', self::INDEX)
            ->exists();
    }
};
