<?php

namespace Tests\Unit;

use App\Domain\CheckoutRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CheckoutRulesTest extends TestCase
{
    public function test_insufficient_provider_evidence_never_manufactures_finality(): void
    {
        foreach (['paid', 'failed', 'expired', 'pending', 'unsupported'] as $status) {
            $this->assertSame('unknown', CheckoutRules::observation(['status' => $status]));
        }
        $this->assertSame('pending', CheckoutRules::observation(['status' => 'pending', 'verified' => true]));
        $this->assertSame('unknown', CheckoutRules::observation(['status' => 'paid', 'verified' => true]));
        $this->assertSame('paid', CheckoutRules::observation(['status' => 'paid', 'verified' => true, 'attempt_specific' => true]));
        foreach (['failed', 'expired'] as $status) {
            $base = ['status' => $status, 'verified' => true, 'attempt_specific' => true];
            $this->assertSame('unknown', CheckoutRules::observation($base));
            $this->assertSame('non_payable', CheckoutRules::observation([...$base, 'non_payable' => true]));
        }
    }

    public function test_tender_and_fulfillment_locks_are_pure_checkout_scoped_rules(): void
    {
        foreach (['open', 'ready_for_attempt', 'payment_unresolved', 'completed', 'paid_unfulfilled', 'provider_contradiction', 'abandoned'] as $state) {
            $this->assertSame(in_array($state, ['open', 'ready_for_attempt'], true), CheckoutRules::canTender($state, false, false));
            $this->assertFalse(CheckoutRules::canTender($state, true, false));
            $this->assertFalse(CheckoutRules::canTender($state, false, true));
            $this->assertFalse(CheckoutRules::canFulfill($state, true, false));
            $this->assertFalse(CheckoutRules::canFulfill($state, false, true));
        }
        $this->assertTrue(CheckoutRules::canFulfill('payment_unresolved', false, false));
        $this->assertFalse(CheckoutRules::canFulfill('abandoned', false, false));
    }

    public function test_integer_centavo_arithmetic_preserves_exact_small_totals_and_boundary(): void
    {
        foreach (range(0, 1000) as $price) {
            $this->assertSame($price * 7 + 3, CheckoutRules::lineTotal(3, $price, 7));
        }
        $this->assertSame(4294967295, CheckoutRules::lineTotal(4294967294, 1, 1));
        $this->expectException(InvalidArgumentException::class);
        CheckoutRules::lineTotal(4294967295, 1, 1);
    }
}
