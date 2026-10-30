<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\Sakemaru\ItemContractor;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real model saves to a dedicated SQLite memory connection; no application bootstrap. */
class SharedQuantityPreservationTest extends TestCase
{
    private Container $originalContainer;

    private $originalResolver;

    private $originalDispatcher;

    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContainer = Container::getInstance();
        $this->originalResolver = Model::getConnectionResolver();
        $this->originalDispatcher = Model::getEventDispatcher();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sakemaru');
        $this->database->getDatabaseManager()->setDefaultConnection('sakemaru');
        $this->database->bootEloquent();
        Model::clearBootedModels();
        Model::unsetEventDispatcher();
        $db = $this->database->getConnection('sakemaru');
        self::assertSame(':memory:', $db->getDatabaseName());
        $db->statement('CREATE TABLE item_contractors (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, safety_stock INTEGER DEFAULT 10, max_stock INTEGER DEFAULT 20, min_stock INTEGER DEFAULT 0, purchase_unit INTEGER DEFAULT 1, auto_order_quantity INTEGER DEFAULT 0, is_auto_order INTEGER DEFAULT 0, use_safety_stock_auto_update INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wms_auto_order_controls (client_id INTEGER PRIMARY KEY, release_key TEXT, reset_state TEXT, enabled INTEGER DEFAULT 0)');
        $db->statement('CREATE TABLE wms_auto_order_events (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, event TEXT, payload TEXT)');
    }

    protected function tearDown(): void
    {
        $this->database->getConnection('sakemaru')->disconnect();
        Model::clearBootedModels();
        if ($this->originalResolver) {
            Model::setConnectionResolver($this->originalResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->originalDispatcher) {
            Model::setEventDispatcher($this->originalDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Container::setInstance($this->originalContainer);
        parent::tearDown();
    }

    private function row(array $overrides = []): array
    {
        return $overrides + ['client_id' => 6, 'warehouse_id' => 9100, 'item_id' => 143024,
            'contractor_id' => 9012, 'supplier_id' => 8007, 'safety_stock' => 123, 'max_stock' => 246,
            'min_stock' => 6, 'purchase_unit' => 12, 'auto_order_quantity' => 24,
            'is_auto_order' => 1, 'use_safety_stock_auto_update' => 1];
    }

    private function control(array $preserved = [9100], string $state = 'COMPLETE'): void
    {
        $db = $this->database->getConnection('sakemaru');
        $db->table('wms_auto_order_controls')->insert(['client_id' => 6, 'release_key' => 'release-test', 'reset_state' => $state, 'enabled' => 0]);
        $db->table('wms_auto_order_events')->insert(['client_id' => 6, 'event' => 'RESET_SCOPE_FIXED', 'payload' => json_encode(['release_key' => 'release-test', 'preserved_warehouse_ids' => $preserved])]);
    }

    public static function warehouses(): array
    {
        return ['frozen warehouse' => [9100, [9100], true], 'ID 91 is not a code' => [91, [9100], false], 'non-Hana profile' => [9100, [], false]];
    }

    #[DataProvider('warehouses')]
    public function test_new_model_preserves_only_frozen_warehouse_quantities(int $warehouse, array $preserved, bool $keep): void
    {
        $this->control($preserved);
        $model = ItemContractor::create($this->row(['warehouse_id' => $warehouse]))->fresh();
        foreach (['safety_stock' => 123, 'max_stock' => 246, 'min_stock' => 6, 'purchase_unit' => 12, 'auto_order_quantity' => 24] as $field => $value) {
            self::assertSame($keep ? $value : 0, (int) $model->$field, $field);
        }
        self::assertFalse($model->is_auto_order);
        self::assertFalse($model->use_safety_stock_auto_update);
    }

    public function test_existing_model_keeps_quantities_and_turns_legacy_flags_off(): void
    {
        $this->control();
        $this->database->getConnection('sakemaru')->table('item_contractors')->insert($this->row(['id' => 44]));
        $model = ItemContractor::findOrFail(44);
        $model->supplier_id = 8008;
        $model->save();
        $model->refresh();
        self::assertSame(123, $model->safety_stock);
        self::assertSame(246, $model->max_stock);
        self::assertSame(12, $model->purchase_unit);
        self::assertSame(24, $model->auto_order_quantity);
        self::assertFalse($model->is_auto_order);
        self::assertFalse($model->use_safety_stock_auto_update);
    }

    public function test_unintroduced_client_keeps_legacy_quantities_and_flags(): void
    {
        $model = ItemContractor::create($this->row())->fresh();
        self::assertSame(123, $model->safety_stock);
        self::assertTrue($model->is_auto_order);
        self::assertTrue($model->use_safety_stock_auto_update);
    }

    public function test_other_client_or_release_does_not_change_the_fixed_scope(): void
    {
        $this->control();
        $db = $this->database->getConnection('sakemaru');
        foreach ([[6, 'another-release'], [1, 'release-test']] as [$client, $release]) {
            $db->table('wms_auto_order_events')->insert(['client_id' => $client, 'event' => 'RESET_SCOPE_FIXED', 'payload' => json_encode(['release_key' => $release, 'preserved_warehouse_ids' => []])]);
        }
        self::assertSame(123, ItemContractor::create($this->row())->fresh()->safety_stock);
    }

    public function test_missing_scope_stops_new_save_without_changing_data(): void
    {
        $db = $this->database->getConnection('sakemaru');
        $db->table('wms_auto_order_controls')->insert(['client_id' => 6, 'release_key' => 'release-test', 'reset_state' => 'COMPLETE']);
        try {
            ItemContractor::create($this->row());
            self::fail('Unknown scope must not silently reset purchasing quantities.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('保持する倉庫を確認できません', $e->getMessage());
            self::assertSame(0, $db->table('item_contractors')->count());
        }
    }

    public function test_reset_in_progress_still_blocks_new_save(): void
    {
        $this->control(state: 'IN_PROGRESS');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('初期化中');
        ItemContractor::create($this->row());
    }
}
