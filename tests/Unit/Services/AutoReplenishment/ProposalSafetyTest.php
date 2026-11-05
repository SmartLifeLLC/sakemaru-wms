<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Services\AutoReplenishment\ProposalSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProposalSafetyTest extends TestCase
{
    #[DataProvider('unsafeProposals')]
    public function test_customer_strategy_cannot_bypass_shared_quantity_limits(mixed $quantity): void
    {
        $this->expectException(\RuntimeException::class);
        (new ProposalSafety)->validate(['min_order_pieces' => 12, 'max_order_pieces' => 60, 'order_multiple_pieces' => 6], ['phase' => 'READY', 'quantity' => $quantity, 'reason' => 'Strategy test'], 12);
    }

    public static function unsafeProposals(): array
    {
        return [[0], [-12], [6], [18], [72], ['24'], [24.0], [PHP_INT_MAX]];
    }

    public function test_valid_proposal_is_allowed_without_changing_quantity(): void
    {
        $proposal = ['phase' => 'READY', 'quantity' => 24, 'reason' => 'Strategy test'];
        (new ProposalSafety)->validate(['min_order_pieces' => 12, 'max_order_pieces' => 60, 'order_multiple_pieces' => 6], $proposal, 12);
        $this->assertSame(24, $proposal['quantity']);
    }
}
