<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\ItemContractors\Schemas\ItemContractorForm;
use App\Filament\Resources\ItemContractors\Tables\ItemContractorsTable;
use App\Models\Sakemaru\ItemContractor;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Mockery;
use Tests\TestCase;

class ItemContractorStockQuantityTest extends TestCase
{
    private const QUANTITY_COLUMNS = ['safety_stock', 'max_stock', 'min_stock', 'auto_order_quantity'];

    public function test_form_save_data_replaces_blank_quantities_with_zero_and_preserves_numbers(): void
    {
        $livewire = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $data = [];
        };
        $schema = ItemContractorForm::configure(Schema::make($livewire)->statePath('data'));

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

        foreach (self::QUANTITY_COLUMNS as $name) {
            $column = $table->getColumn($name);

            foreach (['', null, '   ', 0, '0', 12, '12'] as $input) {
                $expected = blank($input) ? 0 : (int) $input;
                $record = Mockery::mock(ItemContractor::class)->makePartial();
                $record->shouldReceive('update')->once()->with([$name => $expected])->andReturnTrue();
                $column->record($record);
                $column->validate($input);

                $this->assertSame($expected, $column->updateState($input), $name);
            }

            foreach ([-1, 'abc', '1.5'] as $input) {
                $this->assertFalse(Validator::make(['input' => $input], ['input' => $column->getRules()])->passes());
            }
        }
    }
}
