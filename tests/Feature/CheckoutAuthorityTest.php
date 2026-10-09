<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayException;
use App\Models\CheckoutEvent;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\Payments\PayMongoSandboxGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private array $headers;

    private CheckoutTestGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->headers = $this->headersFor($this->cashier);
        $this->gateway = new CheckoutTestGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
        config(['checkout.hold_seconds' => 1800, 'services.paymongo.qr_expiry_seconds' => 1800, 'checkout.simulation_enabled' => false]);
        Http::preventStrayRequests();
    }

    private function headersFor(User $user, string $key = 'purchase'): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'X-PorSca-Contract-Version' => 'porsca-mobile-api-v3', 'Idempotency-Key' => $key];
    }

    private function product(int $price = 505, int $stock = 5): Product
    {
        $product = Product::factory()->create(['price' => $price]);
        $product->inventory()->create(['quantity' => $stock, 'reorder_level' => 1]);

        return $product;
    }

    private function start(?Product $product = null, string $key = 'purchase', int $quantity = 1): array
    {
        $product ??= $this->product();

        return $this->postJson('/api/v1/checkouts', ['items' => [['productId' => $product->id, 'quantity' => $quantity]]],
            ['Idempotency-Key' => $key, ...array_diff_key($this->headers, ['Idempotency-Key' => true])])->assertCreated()->json('data');
    }

    private function quote(array $checkout): array
    {
        return ['revision' => $checkout['revision'], 'acceptedAmountCentavos' => $checkout['amountCentavos']];
    }

    private function qr(array $checkout, string $key = 'qr'): array
    {
        return $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout),
            [...$this->headers, 'Idempotency-Key' => $key])->assertCreated()->json('data');
    }

    private function refresh(array $checkout, ?array $headers = null)
    {
        $id = $checkout['attempts'][count($checkout['attempts']) - 1]['id'];

        return $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts/'.$id.'/refresh', [], $headers ?? $this->headers);
    }

    private function verified(string $status, bool $nonPayable = false): void
    {
        $this->gateway->inspection = ['verified' => true, 'attempt_specific' => true, 'status' => $status,
            'non_payable' => $nonPayable, 'resource_id' => 'pay_fixture'];
    }

    public function test_checkout_has_durable_identity_and_cash_converges_once(): void
    {
        $product = $this->product();
        $cart = ['items' => [['productId' => $product->id, 'quantity' => 2]]];
        $checkout = $this->postJson('/api/v1/checkouts', $cart, $this->headers)->assertCreated()->json('data');
        $this->postJson('/api/v1/checkouts', $cart, $this->headers)->assertOk()->assertJsonPath('data.id', $checkout['id']);
        $this->getJson('/api/v1/checkouts', $this->headers)->assertOk()->assertJsonPath('data.items.0.id', $checkout['id']);
        $cash = [...$this->quote($checkout), 'cashReceivedCentavos' => 2000];
        $receipt = $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', $cash, $this->headers)->assertCreated()
            ->assertJsonPath('data.state', 'completed')->assertJsonPath('data.sale.changeAmountCentavos', 990)->json('data');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', $cash, $this->headers)->assertOk()
            ->assertJsonPath('data.sale.id', $receipt['sale']['id']);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', $cash, [...$this->headers, 'Idempotency-Key' => 'different'])->assertStatus(409);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(3, $product->inventory->fresh()->quantity);
    }

    public function test_exact_and_zero_total_cash_do_not_depend_on_qr_minimum(): void
    {
        foreach ([0, 1, 99, 505] as $price) {
            $checkout = $this->start($this->product($price), 'cash-'.$price);
            $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => $price], $this->headers)
                ->assertCreated()->assertJsonPath('data.sale.changeAmountCentavos', 0);
        }
        $this->assertDatabaseCount('sales', 4);
    }

    public static function invalidMoney(): array
    {
        return [[''], ['5.05'], [5.05], [-1], ['1e3'], [4294967296], [null], [true]];
    }

    #[DataProvider('invalidMoney')]
    public function test_invalid_cash_is_rejected_without_effects(mixed $amount): void
    {
        $product = $this->product();
        $checkout = $this->start($product);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => $amount], $this->headers)->assertStatus(422);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(5, $product->inventory->fresh()->quantity);
    }

    public function test_insufficient_cash_and_stock_roll_back_every_financial_effect(): void
    {
        $product = $this->product();
        $checkout = $this->start($product);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 504], $this->headers)->assertStatus(422);
        $product->inventory()->update(['quantity' => 0]);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 505], $this->headers)->assertStatus(409);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('checkout_operations', 0);
    }

    public function test_creation_and_tender_keys_bind_exact_payloads_and_kind(): void
    {
        $product = $this->product();
        $checkout = $this->start($product);
        $this->postJson('/api/v1/checkouts', ['items' => [['productId' => $product->id, 'quantity' => 2]]], $this->headers)->assertStatus(409);
        $checkout = $this->qr($checkout, 'purchase');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout), $this->headers)->assertOk()
            ->assertJsonPath('data.attempts.0.id', $checkout['attempts'][0]['id']);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 505], $this->headers)->assertStatus(409);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_rollback_refuses_to_erase_durable_checkout_records(): void
    {
        $checkout = $this->start();
        $migration = require database_path('migrations/2026_10_08_130000_create_checkout_authority.php');
        try {
            $migration->down();
            $this->fail('Rollback must not erase durable checkout history.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Checkout authority rollback requires operator archival/reconciliation.', $exception->getMessage());
        }
        $this->assertDatabaseHas('checkouts', ['id' => $checkout['id']]);
        $this->assertDatabaseCount('checkout_events', 1);
    }

    public function test_contract_version_and_keys_are_required_but_rbac_v2_routes_remain_available(): void
    {
        $headers = $this->headers;
        unset($headers['X-PorSca-Contract-Version']);
        $this->getJson('/api/v1/checkouts', $headers)->assertStatus(409);
        $this->getJson('/api/v1/auth/me', [...$headers, 'X-PorSca-Contract-Version' => 'porsca-mobile-api-v2'])->assertOk();
        $headers = $this->headers;
        unset($headers['Idempotency-Key']);
        $this->postJson('/api/v1/checkouts', ['items' => []], $headers)->assertStatus(422);
        $this->getJson('/api/v1/checkout-session', $this->headers)->assertOk()->assertJsonPath('data.user.role', 'cashier')
            ->assertJsonPath('data.user.isActive', true)->assertJsonPath('data.simulationAllowed', false);
    }

    public function test_pending_reads_never_settle_or_unlock_and_hold_expiry_is_separate(): void
    {
        $product = $this->product(505, 1);
        $checkout = $this->qr($this->start($product));
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(1, $product->inventory->fresh()->quantity);
        $this->verified('paid');
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()->assertJsonPath('data.attempts.0.status', 'pending');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 505], $this->headers)->assertStatus(409);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/abandon', ['reason' => 'Customer left store'], $this->headers)->assertStatus(409);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/revalidate', [], $this->headers)->assertStatus(409);
        $this->travel(31)->minutes();
        $this->getJson('/api/v1/checkouts/'.$checkout['id'].'/attempts/'.$checkout['attempts'][0]['id'].'/reservation', $this->headers)
            ->assertOk()->assertJsonPath('data.reservation.state', 'expired');
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()->assertJsonPath('data.attempts.0.status', 'pending');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 505], $this->headers)->assertStatus(409);
        $other = $this->start($product, 'other-customer');
        $this->postJson('/api/v1/checkouts/'.$other['id'].'/cash', [...$this->quote($other), 'cashReceivedCentavos' => 505], $this->headers)->assertCreated();
    }

    public function test_first_verification_failure_becomes_unknown_and_later_pending_is_reversible(): void
    {
        $checkout = $this->qr($this->start());
        $this->gateway->transportFailure = true;
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown')->assertJsonPath('data.attempts.0.financialStatus', 'pending');
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()->assertJsonPath('data.attempts.0.reservation.state', 'held');
        $this->gateway->transportFailure = false;
        $this->gateway->inspection = ['verified' => true, 'status' => 'pending'];
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.attempts.0.status', 'pending');
        $this->gateway->inspection = ['verified' => false, 'status' => 'paid'];
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_provision_failure_preserves_attempt_and_operation_identity_on_retry(): void
    {
        $this->gateway->creationFailure = true;
        $checkout = $this->qr($this->start());
        $this->assertSame('unknown', $checkout['attempts'][0]['status']);
        $attempt = Payment::findOrFail($checkout['attempts'][0]['id']);
        $operationKey = $attempt->provider_operation_key;
        $this->gateway->creationFailure = false;
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout), [...$this->headers, 'Idempotency-Key' => 'qr'])
            ->assertOk()->assertJsonPath('data.attempts.0.id', $attempt->id);
        $this->assertSame($operationKey, $attempt->fresh()->provider_operation_key);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_generic_failed_intent_cannot_prove_attempt_non_payability(): void
    {
        $checkout = $this->qr($this->start());
        $this->gateway->inspection = ['verified' => true, 'status' => 'failed'];
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown');
        $this->travel(31)->minutes();
        $this->gateway->inspection = ['verified' => true, 'status' => 'pending'];
        $this->postJson('/api/v1/payments/'.$checkout['attempts'][0]['id'].'/refresh', [], $this->headers)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 505], $this->headers)->assertStatus(409);
    }

    public function test_verified_non_payability_requires_price_revalidation_and_explicit_acceptance(): void
    {
        $product = $this->product();
        $checkout = $this->qr($this->start($product));
        $this->verified('expired', true);
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'ready_for_attempt');
        $product->update(['price' => 600]);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout), [...$this->headers, 'Idempotency-Key' => 'next'])->assertStatus(409);
        $revised = $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/revalidate', [], $this->headers)->assertOk()
            ->assertJsonPath('data.amountCentavos', 600)->json('data');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 600], $this->headers)->assertStatus(409);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($revised), 'cashReceivedCentavos' => 600], $this->headers)->assertCreated();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_verified_paid_converges_once_and_contradiction_preserves_first_outcome(): void
    {
        $product = $this->product();
        $checkout = $this->qr($this->start($product));
        $this->verified('paid');
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->verified('failed', true);
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'provider_contradiction')
            ->assertJsonPath('data.attempts.0.firstVerifiedOutcome', 'paid')->assertJsonPath('data.attempts.0.financialStatus', 'paid')
            ->assertJsonPath('data.exceptions.0.reason', 'provider_contradiction');
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(4, $product->inventory->fresh()->quantity);
        $this->assertDatabaseCount('reconciliation_cases', 1);
        $this->assertSame(3, CheckoutEvent::where('type', 'provider_observed')->count());
    }

    public function test_first_non_payable_result_also_stands_on_contradictory_paid(): void
    {
        $checkout = $this->qr($this->start());
        $this->verified('failed', true);
        $this->refresh($checkout)->assertOk();
        $this->verified('paid');
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'provider_contradiction')
            ->assertJsonPath('data.attempts.0.financialStatus', 'failed')->assertJsonPath('data.attempts.0.firstVerifiedOutcome', 'non_payable');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_late_paid_must_fulfill_when_safe_and_must_reconcile_when_stock_is_reserved_elsewhere(): void
    {
        $product = $this->product(505, 2);
        $late = $this->qr($this->start($product, 'late'));
        $this->travel(31)->minutes();
        $other = $this->qr($this->start($product, 'other', 2), 'other-qr');
        $this->verified('paid');
        $this->refresh($late)->assertOk()->assertJsonPath('data.state', 'paid_unfulfilled')->assertJsonPath('data.attempts.0.financialStatus', 'paid_unfulfilled');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(2, $product->inventory->fresh()->quantity);
        $this->travel(31)->minutes();
        $this->refresh($other)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(0, $product->inventory->fresh()->quantity);
        $this->refresh($late)->assertOk()->assertJsonPath('data.state', 'paid_unfulfilled');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_another_operator_recovers_multiple_store_checkouts_after_originator_deactivation(): void
    {
        $first = $this->qr($this->start(null, 'first'));
        $second = $this->qr($this->start(null, 'second'), 'second-qr');
        $this->cashier->update(['is_active' => false]);
        $this->getJson('/api/v1/checkouts', $this->headers)->assertUnauthorized();
        $other = User::factory()->create(['role' => 'cashier']);
        $headers = $this->headersFor($other);
        $this->getJson('/api/v1/checkouts', $headers)->assertOk()->assertJsonPath('data.pagination.total', 2);
        $this->postJson('/api/v1/checkouts/'.$first['id'].'/recover', [], $headers)->assertOk()
            ->assertJsonPath('data.attempts.0.id', $first['attempts'][0]['id'])->assertJsonPath('data.items', $first['items']);
        $this->assertDatabaseHas('checkout_events', ['checkout_id' => $first['id'], 'type' => 'checkout_recovered', 'actor_id' => $other->id]);
        config(['checkout.store_id' => 'another-store']);
        $this->getJson('/api/v1/checkouts/'.$second['id'], $headers)->assertNotFound();
        $this->getJson('/api/v1/checkouts', $headers)->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_abandonment_is_terminal_and_attributed_to_any_active_operator(): void
    {
        $checkout = $this->start();
        $other = User::factory()->create(['role' => 'cashier']);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/abandon', ['reason' => 'Customer declined purchase'], $this->headersFor($other))
            ->assertOk()->assertJsonPath('data.state', 'abandoned');
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 505], $this->headers)->assertStatus(409);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout), $this->headers)->assertStatus(409);
        $this->assertDatabaseHas('checkout_events', ['checkout_id' => $checkout['id'], 'type' => 'checkout_abandoned', 'actor_id' => $other->id]);
        $new = $this->start(null, 'new-purchase');
        $this->assertNotSame($checkout['id'], $new['id']);
    }

    private function exceptionCheckout(): array
    {
        $product = $this->product(505, 1);
        $checkout = $this->qr($this->start($product));
        $this->travel(31)->minutes();
        $product->inventory()->update(['quantity' => 0]);
        $this->verified('paid');

        return $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'paid_unfulfilled')->json('data');
    }

    public function test_reconciliation_is_admin_only_evidence_classed_versioned_and_financially_inert(): void
    {
        $checkout = $this->exceptionCheckout();
        $case = ReconciliationCase::firstOrFail();
        $this->getJson('/api/v1/reconciliation-cases/'.$case->id, $this->headers)->assertForbidden();
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, [], $this->headers)->assertForbidden();
        $admin = $this->headersFor($this->admin);
        $this->getJson('/api/v1/reconciliation-cases/'.$case->id, $admin)->assertOk()->assertJsonPath('data.attemptId', $checkout['attempts'][0]['id']);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, ['action' => 'close', 'version' => 1], $admin)->assertStatus(422);
        $input = ['action' => 'close', 'version' => 1, 'category' => 'external_refund_confirmed',
            'explanation' => 'Refund actually completed and checked by administrator.', 'evidenceClass' => 'administrator_attested',
            'evidenceReference' => 'refund-ledger-20261008', 'attested' => true, 'remedyCompleted' => true, 'evidenceContradictory' => false];
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, $input, $admin)->assertStatus(422);
        $input['externalReference'] = 'bank-refund-reference';
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, [...$input, 'evidenceContradictory' => true], $admin)->assertStatus(422);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, [...$input, 'remedyCompleted' => false], $admin)->assertStatus(422);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, [...$input, 'evidenceClass' => 'provider_verified'], $admin)->assertStatus(422);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, $input, $admin)->assertOk()->assertJsonPath('data.state', 'resolved')
            ->assertJsonPath('data.resolution.evidenceClass', 'administrator_attested')->assertJsonPath('data.version', 2);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, $input, $admin)->assertStatus(409);
        $reopened = $this->patchJson('/api/v1/reconciliation-cases/'.$case->id,
            ['action' => 'reopen', 'version' => 2, 'explanation' => 'New evidence needs another investigation.'], $admin)->assertOk()->json('data');
        $this->assertSame('reopened', $reopened['state']);
        $this->assertSame('bank-refund-reference', $reopened['resolution']['externalReference']);
        $this->assertCount(1, array_filter($reopened['history'], fn ($event) => $event['type'] === 'case_close'));
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('paid_unfulfilled', Payment::find($case->payment_id)->status);
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()
            ->assertJsonMissing(['evidenceReference' => 'refund-ledger-20261008'])->assertJsonPath('data.exceptions.0.state', 'reopened');
    }

    public function test_staging_test_evidence_cannot_be_mislabeled_outside_approved_staging(): void
    {
        $this->exceptionCheckout();
        $case = ReconciliationCase::firstOrFail();
        $input = ['action' => 'close', 'version' => 1, 'category' => 'other_verified_remedy', 'explanation' => 'Test-only remedy completed in approved staging.',
            'evidenceClass' => 'staging_test', 'evidenceReference' => 'staging-test-reference', 'attested' => true,
            'remedyCompleted' => true, 'evidenceContradictory' => false];
        $admin = $this->headersFor($this->admin);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, $input, $admin)->assertForbidden();
        $this->app->instance('env', 'staging');
        config(['checkout.simulation_enabled' => true]);
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, $input, $admin)->assertOk()->assertJsonPath('data.resolution.evidenceClass', 'staging_test');
    }

    public function test_only_approved_staging_admin_can_retrieve_official_capability_and_no_history_leaks_it(): void
    {
        $checkout = $this->qr($this->start());
        $attempt = Payment::findOrFail($checkout['attempts'][0]['id']);
        $path = '/api/v1/checkouts/'.$checkout['id'].'/attempts/'.$attempt->id.'/simulation-capability';
        $url = 'https://test.paymongo.test/simulate/private-fixture';
        config(['services.paymongo.secret_key' => 'sk_test_fixture', 'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents']);
        Http::fake(['https://api.paymongo.test/v1/payment_intents/'.$attempt->provider_payment_id => Http::response(['data' => [
            'id' => $attempt->provider_payment_id, 'attributes' => ['amount' => 505, 'currency' => 'PHP', 'livemode' => false,
                'payment_method' => $attempt->provider_method_id, 'next_action' => ['code' => ['test_url' => $url]]],
        ]])]);
        $this->postJson($path)->assertUnauthorized();
        $this->postJson($path, [], $this->headers)->assertForbidden();
        $admin = $this->headersFor($this->admin);
        config(['checkout.simulation_enabled' => true]);
        $this->postJson($path, [], $admin)->assertForbidden();
        $this->app->instance('env', 'staging');
        $this->postJson($path, [], $admin)->assertOk()->assertJsonPath('data.url', $url)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()->assertDontSee($url, false);
        $this->getJson('/api/v1/payments/'.$attempt->id, $this->headers)->assertOk()->assertDontSee($url, false);
        $this->getJson('/api/v1/transactions', $this->headers)->assertOk()->assertDontSee($url, false);
        $this->assertDatabaseHas('checkout_events', ['type' => 'simulation_retrieved', 'actor_id' => $this->admin->id]);
        $this->assertStringNotContainsString($url, json_encode(CheckoutEvent::all()->toArray()));
        $this->admin->update(['is_active' => false]);
        $this->postJson($path, [], $admin)->assertUnauthorized();
        Http::assertSentCount(1);
    }

    public function test_signed_duplicate_webhooks_and_refresh_converge_on_same_checkout(): void
    {
        $product = $this->product();
        $checkout = $this->qr($this->start($product));
        $attempt = Payment::findOrFail($checkout['attempts'][0]['id']);
        $this->verified('paid');
        config(['services.paymongo.webhook_secret' => 'webhook-fixture']);
        $payload = json_encode(['data' => ['id' => 'evt_checkout_paid', 'attributes' => ['type' => 'payment.paid',
            'data' => ['id' => $attempt->provider_payment_id]]]]);
        $stamp = now()->timestamp;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$stamp.',te='.hash_hmac('sha256', $stamp.'.'.$payload, 'webhook-fixture')];
        $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], $headers, $payload)->assertOk();
        $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], $headers, $payload)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(4, $product->inventory->fresh()->quantity);
    }

    public function test_real_adapter_fails_closed_on_unapproved_or_uncorrelated_finality(): void
    {
        $checkout = $this->qr($this->start());
        $attempt = Payment::findOrFail($checkout['attempts'][0]['id']);
        $this->app->instance(PaymentGateway::class, new PayMongoSandboxGateway);
        config(['services.paymongo.secret_key' => 'sk_test_fixture', 'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents']);
        $url = 'https://api.paymongo.test/v1/payment_intents/'.$attempt->provider_payment_id;
        $attributes = ['amount' => 505, 'currency' => 'PHP', 'livemode' => false, 'status' => 'succeeded',
            'payment_method' => $attempt->provider_method_id, 'payments' => [['id' => $attempt->provider_resource_id]]];
        Http::fake([$url => Http::sequence()
            ->push(['data' => ['id' => $attempt->provider_payment_id, 'attributes' => $attributes]])
            ->push(['data' => ['id' => $attempt->provider_payment_id, 'attributes' => [...$attributes, 'payment_method' => 'pm_unrelated']]])
            ->push(['data' => ['id' => $attempt->provider_payment_id, 'attributes' => [...$attributes, 'amount' => 999]]])
            ->push(['data' => ['id' => $attempt->provider_payment_id, 'attributes' => [...$attributes, 'livemode' => true]]])
            ->push(['data' => ['id' => $attempt->provider_payment_id, 'attributes' => $attributes]])]);
        config(['checkout.provider_finality_enabled' => false]);
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown');
        config(['checkout.provider_finality_enabled' => true]);
        foreach (range(1, 3) as $check) {
            $this->refresh($checkout)->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown');
        }
        $this->assertDatabaseCount('sales', 0);
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_abandoned_checkout_remains_terminal_after_contradictory_late_paid(): void
    {
        $checkout = $this->qr($this->start());
        $this->verified('expired', true);
        $this->refresh($checkout)->assertOk();
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/abandon', ['reason' => 'Customer declined retry'], $this->headers)
            ->assertOk()->assertJsonPath('data.state', 'abandoned');
        $this->verified('paid');
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.state', 'abandoned')
            ->assertJsonPath('data.attempts.0.status', 'contradiction')->assertJsonPath('data.exceptions.0.reason', 'provider_contradiction');
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_contradiction_after_case_closure_reopens_without_erasing_resolution(): void
    {
        $checkout = $this->qr($this->start());
        $this->verified('paid');
        $this->refresh($checkout)->assertOk();
        $this->verified('failed', true);
        $this->refresh($checkout)->assertOk();
        $case = ReconciliationCase::firstOrFail();
        $admin = $this->headersFor($this->admin);
        $input = ['action' => 'close', 'version' => 1, 'category' => 'other_verified_remedy',
            'explanation' => 'Independent transaction investigation completed.',
            'evidenceClass' => 'administrator_attested', 'evidenceReference' => 'investigation-record-20261008',
            'attested' => true, 'remedyCompleted' => true, 'evidenceContradictory' => false];
        $this->patchJson('/api/v1/reconciliation-cases/'.$case->id, $input, $admin)->assertOk();
        $this->refresh($checkout)->assertOk()->assertJsonPath('data.exceptions.0.state', 'reopened');
        $response = $this->getJson('/api/v1/reconciliation-cases/'.$case->id, $admin)->assertOk()
            ->assertJsonPath('data.resolution.evidenceReference', 'investigation-record-20261008')->assertJsonPath('data.version', 3)->json('data');
        $this->assertCount(3, $response['providerHistory']);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_webhook_transport_failure_can_be_verified_on_redelivery(): void
    {
        $checkout = $this->qr($this->start());
        $attempt = Payment::findOrFail($checkout['attempts'][0]['id']);
        config(['services.paymongo.webhook_secret' => 'webhook-fixture']);
        $payload = json_encode(['data' => ['id' => 'evt_retry_after_transport', 'attributes' => ['type' => 'payment.paid',
            'data' => ['id' => $attempt->provider_payment_id]]]]);
        $stamp = now()->timestamp;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => 't='.$stamp.',te='.hash_hmac('sha256', $stamp.'.'.$payload, 'webhook-fixture')];
        $this->gateway->transportFailure = true;
        $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], $headers, $payload)->assertOk();
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()->assertJsonPath('data.attempts.0.status', 'unknown');
        $this->gateway->transportFailure = false;
        $this->verified('paid');
        $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], $headers, $payload)->assertOk();
        $this->getJson('/api/v1/checkouts/'.$checkout['id'], $this->headers)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(2, CheckoutEvent::where('type', 'provider_observed')->count());
    }

    public function test_qr_duration_misalignment_and_provisional_minimum_do_not_block_cash(): void
    {
        $checkout = $this->start($this->product(99));
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout), $this->headers)->assertStatus(422);
        config(['checkout.hold_seconds' => 60]);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/attempts', $this->quote($checkout), $this->headers)->assertStatus(503);
        $this->postJson('/api/v1/checkouts/'.$checkout['id'].'/cash', [...$this->quote($checkout), 'cashReceivedCentavos' => 99], $this->headers)->assertCreated();
    }
}

/** Executable authority seam fixture; never a production simulator. */
class CheckoutTestGateway implements PaymentGateway
{
    public bool $transportFailure = false;

    public bool $creationFailure = false;

    public array $inspection = ['verified' => true, 'status' => 'pending'];

    public function createQrPayment(Payment $payment): array
    {
        if ($this->creationFailure) {
            throw new PaymentGatewayException('Fixture creation timeout.');
        }
        $payment->update(['provider_method_id' => 'pm_'.$payment->id, 'provider_resource_id' => 'pay_'.$payment->id]);

        return ['provider_payment_id' => 'pi_'.$payment->id, 'qr_payload' => 'data:image/png;base64,fixture', 'checkout_url' => null,
            'metadata' => ['driver' => 'test-only']];
    }

    public function inspect(Payment $payment): array
    {
        if ($this->transportFailure) {
            throw new PaymentGatewayException('Fixture verification timeout.');
        }

        return $this->inspection;
    }

    public function status(Payment $payment): string
    {
        return $this->inspection['status'] ?? 'pending';
    }
}
