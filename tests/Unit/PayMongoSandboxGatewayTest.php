<?php

namespace Tests\Unit;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment;
use App\Services\Payments\PayMongoSandboxGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayMongoSandboxGatewayTest extends TestCase
{
    public function test_missing_key_uses_a_labeled_local_sandbox_fixture(): void
    {
        config(['services.paymongo.mode' => 'sandbox', 'services.paymongo.secret_key' => null]);
        $payment = new Payment(['amount' => 1000, 'currency' => 'PHP']);

        $result = (new PayMongoSandboxGateway)->createQrPayment($payment);

        $this->assertStringStartsWith('sandbox_', $result['provider_payment_id']);
        $this->assertStringContainsString('sandbox', $result['qr_payload']);
        $this->assertSame('local-sandbox-fixture', $result['metadata']['driver']);
    }

    public function test_configured_key_creates_a_qrph_payment_and_normalizes_status(): void
    {
        $secret = 'sk_test_fixture';
        config([
            'services.paymongo.mode' => 'sandbox',
            'services.paymongo.secret_key' => $secret,
            'services.paymongo.endpoint' => 'https://api.paymongo.test/v1/payment_intents',
        ]);
        Http::fake([
            'https://api.paymongo.test/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_001',
                    'attributes' => ['qr_code' => 'qr://test', 'status' => 'awaiting_payment'],
                ],
            ], 201),
            'https://api.paymongo.test/v1/payment_intents/pi_test_001' => Http::response([
                'data' => ['id' => 'pi_test_001', 'attributes' => ['status' => 'succeeded', 'amount' => 1000, 'currency' => 'PHP', 'livemode' => false]],
            ]),
        ]);
        $payment = new Payment([
            'amount' => 1000,
            'currency' => 'PHP',
            'id' => 10,
            'provider_operation_key' => 'op_fixture',
        ]);

        $gateway = new PayMongoSandboxGateway;
        $created = $gateway->createQrPayment($payment);
        $this->assertSame('pi_test_001', $created['provider_payment_id']);
        $this->assertSame('qr://test', $created['qr_payload']);
        $payment->provider_payment_id = $created['provider_payment_id'];
        $this->assertSame(Payment::PAID, $gateway->status($payment));

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization') && str_contains($request->body(), 'qrph'));
    }

    public function test_non_sandbox_mode_is_rejected(): void
    {
        config(['services.paymongo.mode' => 'production']);
        $this->expectException(PaymentGatewayException::class);

        (new PayMongoSandboxGateway)->createQrPayment(new Payment(['amount' => 100]));
    }
}
