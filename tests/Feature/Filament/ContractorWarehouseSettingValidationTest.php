<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Contractors\Pages\EditContractor;
use App\Filament\Resources\Contractors\RelationManagers\WarehouseSettingsRelationManager;
use App\Filament\Resources\WmsContractorWarehouseSettings\Pages\CreateWmsContractorWarehouseSetting;
use App\Filament\Resources\WmsContractorWarehouseSettings\Pages\EditWmsContractorWarehouseSetting;
use App\Filament\Resources\WmsContractorWarehouseSettings\Pages\ListWmsContractorWarehouseSettings;
use App\Filament\Resources\WmsContractorWarehouseSettings\WmsContractorWarehouseSettingResource;
use App\Filament\Support\ContractorWarehouseSettingCreation;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\User;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorWarehouseSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class ContractorWarehouseSettingValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    private Contractor $contractor;

    private Contractor $otherContractor;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(PermissionService::class, fn ($mock) => $mock->shouldReceive('check')->andReturnTrue());
        $user = User::query()->firstOrFail();
        $this->actingAs($user);

        // Connection-local fixture: never create or alter the persistent settings table.
        Schema::connection('sakemaru')->create('wms_contractor_warehouse_settings', function (Blueprint $table): void {
            $table->temporary();
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('contractor_id');
            $table->string('designated_code')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'contractor_id'], 'uniq_wcws_warehouse_contractor');
        });

        $this->contractor = $this->createContractor((int) $user->client_id);
        $this->otherContractor = $this->createContractor((int) $user->client_id);
        $warehouses = Warehouse::query()->limit(2)->get();
        $this->assertCount(2, $warehouses, '専用テストDBに2件の倉庫マスタが必要です。');
        $this->warehouse = $warehouses[0];
        $this->otherWarehouse = $warehouses[1];
    }

    public static function creationEntrypoints(): array
    {
        return [
            'new page' => ['page'],
            'contractor relation modal' => ['relation'],
        ];
    }

    #[DataProvider('creationEntrypoints')]
    public function test_duplicate_shows_a_japanese_field_error_and_preserves_the_existing_setting(string $entrypoint): void
    {
        $existing = WmsContractorWarehouseSetting::create($this->data());
        $data = $this->data(['designated_code' => '9999']);
        $component = $this->submit($entrypoint, $data, 'unique');

        $this->assertDuplicateMessageIsRendered($component, $entrypoint);
        $this->assertSame('4822', $existing->refresh()->designated_code);
        $this->assertSame(1, WmsContractorWarehouseSetting::count());
    }

    #[DataProvider('creationEntrypoints')]
    public function test_other_warehouse_or_other_contractor_can_be_registered(string $entrypoint): void
    {
        WmsContractorWarehouseSetting::create($this->data());

        $this->submit($entrypoint, $this->data(['warehouse_id' => $this->otherWarehouse->id]));
        $this->submit($entrypoint, $this->data(['contractor_id' => $this->otherContractor->id]));

        $this->assertSame(3, WmsContractorWarehouseSetting::count());
    }

    #[DataProvider('creationEntrypoints')]
    public function test_insert_after_validation_is_also_shown_as_a_field_error(string $entrypoint): void
    {
        WmsContractorWarehouseSetting::creating(function (WmsContractorWarehouseSetting $record): void {
            // Simulate another request inserting the same pair after validation has passed.
            DB::connection('sakemaru')->table($record->getTable())->insert([
                'warehouse_id' => $record->warehouse_id,
                'contractor_id' => $record->contractor_id,
                'designated_code' => '先に保存された設定',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $component = $this->submit($entrypoint, $this->data(), true);
        $this->assertDuplicateMessageIsRendered($component, $entrypoint);

        $this->assertSame(1, WmsContractorWarehouseSetting::count());
        $this->assertSame('先に保存された設定', WmsContractorWarehouseSetting::sole()->designated_code);
    }

    #[DataProvider('creationEntrypoints')]
    public function test_existing_designated_code_can_still_be_edited(string $entrypoint): void
    {
        $record = WmsContractorWarehouseSetting::create($this->data());

        if ($entrypoint === 'page') {
            Livewire::test(EditWmsContractorWarehouseSetting::class, ['record' => $record->getRouteKey()])
                ->fillForm(['designated_code' => '変更後'])
                ->call('save')
                ->assertHasNoFormErrors();
        } else {
            $this->creationComponent($entrypoint, $this->data())
                ->callTableAction('edit', $record, data: ['designated_code' => '変更後'])
                ->assertHasNoTableActionErrors();
        }

        $this->assertSame('変更後', $record->refresh()->designated_code);
        $this->assertSame(1, WmsContractorWarehouseSetting::count());
    }

    public function test_unrelated_unique_constraints_are_not_disguised_as_duplicate_settings(): void
    {
        $exception = new UniqueConstraintViolationException(
            'sakemaru',
            'insert into wms_contractor_warehouse_settings (...) values (...)',
            [],
            new PDOException("Duplicate entry '1' for key 'some_other_unique_constraint'", 23000),
        );
        WmsContractorWarehouseSetting::creating(function () use ($exception): void {
            throw $exception;
        });

        $this->expectExceptionObject($exception);

        ContractorWarehouseSettingCreation::create($this->data(), 'data');
    }

    public function test_list_create_button_leads_to_the_validated_create_page(): void
    {
        Livewire::test(ListWmsContractorWarehouseSettings::class)
            ->assertActionHasUrl('create', WmsContractorWarehouseSettingResource::getUrl('create'));
    }

    private function assertDuplicateMessageIsRendered(Testable $component, string $entrypoint): void
    {
        if ($entrypoint === 'page') {
            $component->assertSee(ContractorWarehouseSettingCreation::DUPLICATE_MESSAGE);

            return;
        }

        $this->assertStringContainsString(
            ContractorWarehouseSettingCreation::DUPLICATE_MESSAGE,
            $component->getMountedActionModalHtml(),
        );
    }

    private function submit(string $entrypoint, array $data, string|bool|null $error = null): Testable
    {
        $component = $this->creationComponent($entrypoint, $data);
        $errors = $error === true ? ['warehouse_id'] : ['warehouse_id' => $error];

        if ($entrypoint === 'page') {
            $component->fillForm($data)->call('create');

            return $error
                ? $component->assertHasFormErrors($errors)->assertFormSet($data)
                : $component->assertHasNoFormErrors();
        }

        $component->callTableAction('create', data: $data);

        return $error
            ? $component->assertHasTableActionErrors($errors)->assertTableActionDataSet([
                'warehouse_id' => $data['warehouse_id'],
                'designated_code' => $data['designated_code'],
            ])
            : $component->assertHasNoTableActionErrors();
    }

    private function creationComponent(string $entrypoint, array $data): Testable
    {
        return match ($entrypoint) {
            'page' => Livewire::test(CreateWmsContractorWarehouseSetting::class),
            'relation' => Livewire::test(WarehouseSettingsRelationManager::class, [
                'ownerRecord' => Contractor::findOrFail($data['contractor_id']),
                'pageClass' => EditContractor::class,
            ]),
        };
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'contractor_id' => $this->contractor->id,
            'warehouse_id' => $this->warehouse->id,
            'designated_code' => '4822',
        ], $overrides);
    }

    private function createContractor(int $clientId): Contractor
    {
        return Contractor::create([
            'client_id' => $clientId,
            'code' => random_int(700000000, 799999999),
            'name' => '重複検証テスト発注先',
            'delivery_type' => 'DIRECT',
            'is_active' => true,
        ]);
    }
}
