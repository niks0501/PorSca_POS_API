<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Sale;
use App\Services\CashSaleService;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CheckoutController extends ApiController
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly CashSaleService $cashSales,
    ) {}

    public function store(Request $request)
    {
        return $this->process($request);
    }

    public function cash(Request $request)
    {
        return $this->process($request, 'cash');
    }

    private function process(Request $request, ?string $forcedPaymentMethod = null)
    {
        $input = $this->normalizeInput($request, $forcedPaymentMethod);
        $validator = Validator::make($input, [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'qrph'])],
            'cash_received' => [
                Rule::requiredIf($input['payment_method'] === 'cash'),
                'integer',
                'min:0',
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', 'The request could not be validated.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        if ($validated['payment_method'] === 'cash') {
            $wasExisting = Sale::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->exists();
            $sale = $this->cashSales->create(
                $validated['idempotency_key'],
                $validated['items'],
                (int) $validated['cash_received'],
            );

            return $this->data($this->saleArray($sale), $wasExisting ? 200 : 201);
        }

        $wasExisting = Payment::where('idempotency_key', $validated['idempotency_key'])->exists();
        $payment = $this->checkout->create($validated['idempotency_key'], $validated['items']);

        return $this->data($this->paymentArray($payment), $wasExisting ? 200 : 201);
    }

    private function normalizeInput(Request $request, ?string $forcedPaymentMethod): array
    {
        $input = $request->all();
        $bodyIdempotencyKey = $input['idempotency_key'] ?? $input['idempotencyKey'] ?? '';
        $input['idempotency_key'] = trim((string) $request->header('Idempotency-Key', $bodyIdempotencyKey));

        $cashAliases = ['cashReceived', 'cash_amount', 'cashAmount', 'amount_tendered', 'amountTendered', 'tendered_amount', 'cash'];
        $paymentMethod = $input['payment_method'] ?? $input['paymentMethod'] ?? null;
        if ($paymentMethod === null && (array_key_exists('cash_received', $input) || count(array_intersect_key($input, array_flip($cashAliases))) > 0)) {
            $paymentMethod = 'cash';
        }
        $input['payment_method'] = strtolower(trim((string) ($forcedPaymentMethod ?? $paymentMethod ?? 'qrph')));

        foreach ($cashAliases as $alias) {
            if (! array_key_exists('cash_received', $input) && array_key_exists($alias, $input)) {
                $input['cash_received'] = $input[$alias];
            }
        }

        if (is_array($input['items'] ?? null)) {
            $input['items'] = array_map(function (mixed $item): mixed {
                if (! is_array($item)) {
                    return $item;
                }

                if (! array_key_exists('product_id', $item) && array_key_exists('productId', $item)) {
                    $item['product_id'] = $item['productId'];
                }

                return $item;
            }, $input['items']);
        }

        return $input;
    }
}
