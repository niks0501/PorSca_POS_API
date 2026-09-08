<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends ApiController
{
    public function __construct(private readonly CheckoutService $checkout) {}

    public function store(Request $request)
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', $request->input('idempotency_key', '')));
        $input = $request->all();
        $input['idempotency_key'] = $idempotencyKey;
        $validator = Validator::make($input, [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        if ($validator->fails()) {
            return $this->error('validation_error', 'The request could not be validated.', 422, $validator->errors()->toArray());
        }

        $wasExisting = Payment::where('idempotency_key', $idempotencyKey)->exists();
        $payment = $this->checkout->create($idempotencyKey, $request->input('items'));

        return $this->data($this->paymentArray($payment), $wasExisting ? 200 : 201);
    }
}
