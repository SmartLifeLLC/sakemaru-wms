<?php

namespace Tests\Feature\AutoOrder;

use App\Models\WmsAutoOrderRunLine;
use App\Models\WmsOrderCandidate;
use App\Models\WmsOrderJxDocument;
use App\Services\AutoOrder\Generators\HanaOrderJXFileGenerator;
use App\Services\AutoOrder\OrderTransmissionService;
use App\Services\AutoReplenishment\JxGenerationGuard;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeLegacyEosSlipNumberService;
use Tests\Support\JxGeneratorTestCase;

class JxGenerationAccountingTest extends JxGeneratorTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::connection('sakemaru')->getSchemaBuilder();
        $schema->table('wms_order_incoming_schedules', function (Blueprint $table): void {
            $table->integer('expected_quantity');
            $table->string('quantity_type');
        });
        $schema->create('wms_auto_order_run_lines', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('client_id');
            $table->integer('order_candidate_id');
            $table->string('effect_key');
            $table->integer('quantity_pieces');
            $table->decimal('amount', 16, 4);
            $table->string('phase');
            $table->string('reason')->nullable();
            $table->text('confirmation_snapshot');
            $table->text('artifact_output_snapshot')->nullable();
            $table->timestamps();
        });
        $schema->create('wms_auto_order_budget_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('effect_key');
            $table->string('state');
            $table->integer('quantity_pieces');
            $table->decimal('amount', 16, 4);
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
        });
        $schema->create('wms_auto_order_artifact_links', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('line_id');
            $table->string('artifact_type');
            $table->integer('artifact_id');
            $table->string('business_number')->nullable();
            $table->text('snapshot');
            $table->timestamps();
        });
        $schema->create('wms_order_generation_claims', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('candidate_id');
            $table->string('token');
            $table->string('state');
            $table->integer('document_id')->nullable();
            $table->timestamps();
        });
    }

    public static function quantities(): array
    {
        return [
            'six-can code, eight cases (production regression)' => ['CASE', 8, 24, 6, '123.17', '2956.08', '23648.6400', '23648.6400'],
            'piece input converted to six-can ordering cases' => ['PIECE', 192, 24, 6, '123.17', '2956.08', '0', '23648.6400'],
            'ordinary cases' => ['CASE', 8, 24, 1, '123.17', '2956.08', '0', '23648.6400'],
            'case code' => ['CASE', 8, 24, 24, '123.17', '2956.08', '0', '23648.6400'],
            'pieces with decimal price' => ['PIECE', 85, 24, 1, '159.25', '3822.00', '0', '13536.2500'],
            'single-piece case uses piece price' => ['CASE', 3, 1, 1, '1580.00', '0', '0', '4740.0000'],
            'zero price' => ['PIECE', 1, 24, 1, '0', '0', '0', '0.0000'],
            'existing higher reservation is preserved' => ['CASE', 2, 24, 1, '100.00', '2400.00', '5000.0000', '5000.0000'],
        ];
    }

    #[DataProvider('quantities')]
    public function test_publication_accounts_in_the_jx_price_unit_without_changing_order_artifacts(
        string $type, int $quantity, int $capacity, int $pack, string $unitPrice, string $casePrice,
        string $reservedAmount, string $expectedAmount
    ): void {
        $db = DB::connection('sakemaru');
        $db->table('items')->where('id', 143000)->update(['capacity_case' => $capacity]);
        $db->table('item_prices')->where('item_id', 143000)->update(['cost_unit_price' => $unitPrice, 'cost_case_price' => $casePrice]);
        $db->table('item_quantity_information')->where('item_id', 143000)->update(['quantity' => $pack]);
        $db->table('wms_order_candidates')->where('id', 1)->update(['quantity_type' => $type, 'order_quantity' => $quantity]);
        $candidate = WmsOrderCandidate::with(['item', 'warehouse', 'contractor'])->findOrFail(1);
        $candidate->setAttribute('item_code', $candidate->item->code);
        $pieces = $type === 'CASE' ? $quantity * $capacity : $quantity;
        $db->table('wms_order_incoming_schedules')->insert([
            'id' => 1, 'order_candidate_id' => 1, 'slip_number' => '01461012368',
            'expected_quantity' => $quantity, 'quantity_type' => $type,
        ]);
        $line = WmsAutoOrderRunLine::create([
            'client_id' => 6, 'order_candidate_id' => 1, 'effect_key' => 'accounting-test',
            'quantity_pieces' => $pieces, 'amount' => $reservedAmount, 'phase' => 'EOS_CONFIRMED',
            'confirmation_snapshot' => ['policy' => ['max_order_pieces' => $pieces]],
        ]);
        $db->table('wms_auto_order_budget_entries')->insert([
            'effect_key' => 'accounting-test', 'state' => 'RESERVED',
            'quantity_pieces' => $pieces, 'amount' => $reservedAmount,
        ]);
        $db->table('wms_order_generation_claims')->insert(['candidate_id' => 1, 'token' => 'accounting-token', 'state' => 'BUILDING']);

        $generator = new HanaOrderJXFileGenerator(new FakeLegacyEosSlipNumberService);
        $file = $generator->generate(collect([$candidate]))[0];
        $details = app(OrderTransmissionService::class)->extractJxDetailRowsFromContent($file['content']);
        $beforeCandidate = $db->table('wms_order_candidates')->where('id', 1)->first();
        $beforeSchedule = $db->table('wms_order_incoming_schedules')->where('id', 1)->first();
        $document = new WmsOrderJxDocument;
        $document->forceFill(['id' => 10, 'status' => 'PENDING', 'content_sha256' => hash('sha256', $file['content'])]);
        $beforeDocument = $document->getAttributes();
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('event')->once()->with(6, 'JX_GENERATED', ['document_id' => 10], $line->id);

        (new JxGenerationGuard($guard))->recordPublication(collect([$candidate]), $document, 'accounting-token', $file, $details);

        $line->refresh();
        $budget = $db->table('wms_auto_order_budget_entries')->first();
        $this->assertSame(0, bccomp($expectedAmount, (string) $line->amount, 4));
        $this->assertSame(0, bccomp($expectedAmount, (string) $budget->amount, 4));
        $this->assertSame('COMMITTED', $budget->state);
        $this->assertSame($pieces, $line->quantity_pieces);
        $this->assertSame($pieces, $budget->quantity_pieces);
        $this->assertSame('EOS_JX_READY', $line->phase);
        $this->assertSame($details[0], $line->artifact_output_snapshot['detail']);
        $this->assertEquals($beforeCandidate, $db->table('wms_order_candidates')->where('id', 1)->first());
        $this->assertEquals($beforeSchedule, $db->table('wms_order_incoming_schedules')->where('id', 1)->first());
        $this->assertSame($beforeDocument, $document->getAttributes());
        $this->assertSame(hash('sha256', $file['content']), $line->artifact_output_snapshot['document']['content_sha256']);
        $this->assertSame('PUBLISHED', $db->table('wms_order_generation_claims')->value('state'));
        Http::assertNothingSent();
    }
}
