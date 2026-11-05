<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\ItemContractors\Schemas\ItemContractorForm;
use App\Filament\Resources\ItemContractors\Tables\ItemContractorsTable;
use App\Models\Sakemaru\ItemContractor;
use App\Services\ItemContractorReorderPoint;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Mockery;
use Tests\TestCase;

class ItemContractorStockQuantityTest extends TestCase
{
    private const QUANTITY_COLUMNS = ['safety_stock', 'min_stock'];

    public function test_form_save_data_replaces_blank_quantities_with_zero_and_preserves_numbers(): void
    {
        $livewire = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $data = [];
        };
        $schema = ItemContractorForm::configure(Schema::make($livewire)->statePath('data'));
        $status = $schema->getComponentByStatePath('automatic_order_status', withHidden: true);
        $this->assertInstanceOf(TextEntry::class, $status);
        $this->assertFalse($status->isDehydrated());

        foreach (['max_stock', 'auto_order_quantity', 'is_auto_order', 'use_safety_stock_auto_update'] as $name) {
            $this->assertNull($schema->getComponentByStatePath($name, withHidden: true));
        }

        foreach (self::QUANTITY_COLUMNS as $name) {
            $field = $schema->getComponentByStatePath($name, withHidden: true);

            foreach (['', null, '   ', 0, '0', 12, '12'] as $input) {
                $livewire->data[$name] = $input;
                $this->assertTrue(Validator::make([$name => $input], [$name => $field->getValidationRules()])->passes());

                $data = $field->getStateToDehydrate($input);
                $value = $data["data.{$name}"];
                $this->assertEquals(blank($input) ? 0 : $input, $value, $name);
                $this->assertNotNull($value, $name);

                if (blank($input)) {
                    $this->assertSame(0, $value, $name);
                }
            }

            foreach ([-1, 'abc'] as $input) {
                $this->assertFalse(Validator::make([$name => $input], [$name => $field->getValidationRules()])->passes());
            }
        }
    }

    public function test_inline_edit_saves_and_returns_zero_for_blank_quantities(): void
    {
        $table = ItemContractorsTable::configure(Table::make(Mockery::mock(HasTable::class)));
        $this->assertInstanceOf(TextColumn::class, $table->getColumn('is_auto_order_active'));

        foreach (['max_stock', 'auto_order_quantity', 'is_auto_order', 'use_safety_stock_auto_update'] as $name) {
            $this->assertNull($table->getColumn($name));
        }

        foreach (self::QUANTITY_COLUMNS as $name) {
            $column = $table->getColumn($name);

            foreach (['', null, '   ', 0, '0', 12, '12'] as $input) {
                $expected = blank($input) ? 0 : (int) $input;
                $record = Mockery::mock(ItemContractor::class)->makePartial();
                if ($name === 'safety_stock') {
                    $writer = Mockery::mock(ItemContractorReorderPoint::class);
                    $writer->shouldReceive('update')->once()->with($record, [$name => blank($input) ? null : $input])
                        ->andReturn((new ItemContractor)->forceFill(['safety_stock' => $expected]));
                    $this->app->instance(ItemContractorReorderPoint::class, $writer);
                } else {
                    $record->shouldReceive('update')->once()->with([$name => $expected])->andReturnTrue();
                }
                $column->record($record);
                $column->validate($input);

                $this->assertSame($expected, $column->updateState($input), $name);
            }

            foreach ([-1, 'abc', '1.5'] as $input) {
                $this->assertFalse(Validator::make(['input' => $input], ['input' => $column->getRules()])->passes());
            }
        }
    }

    public function test_automatic_products_show_a_readonly_reorder_point_in_the_edit_form(): void
    {
        $livewire = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $data = [];
        };
        $schema = ItemContractorForm::configure(Schema::make($livewire)->statePath('data'))
            ->record((new ItemContractor)->forceFill(['is_auto_order_active' => 1]));
        $point = $schema->getComponentByStatePath('safety_stock', withHidden: true);
        $status = $schema->getComponentByStatePath('automatic_reorder_point', withHidden: true);
        self::assertFalse($point->isVisible());
        self::assertTrue($status->isVisible());
        self::assertSame('自動発注中', $status->getState());
        self::assertFalse($status->isDehydrated());
    }
}
