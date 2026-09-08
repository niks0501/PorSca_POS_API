<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    protected function data(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    protected function error(string $code, string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if ($errors !== []) {
            $payload['error']['details'] = $errors;
        }

        return response()->json($payload, $status);
    }

    protected function productArray($product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'category' => $product->category,
            'description' => $product->description,
            'price' => $product->price,
            'currency' => $product->currency,
            'active' => $product->active,
            'stock' => $product->relationLoaded('inventory')
                ? $this->stockArray($product->inventory)
                : null,
        ];
    }

    protected function stockArray(?Inventory $inventory): ?array
    {
        if ($inventory === null) {
            return null;
        }

        $quantity = (int) $inventory->quantity;
        $reorderLevel = (int) $inventory->reorder_level;

        return [
            'quantity' => $quantity,
            'reorder_level' => $reorderLevel,
            'status' => $this->stockStatus($quantity, $reorderLevel),
            'low_stock' => $quantity <= $reorderLevel,
            'out_of_stock' => $quantity === 0,
        ];
    }

    protected function stockStatus(int $quantity, int $reorderLevel): string
    {
        return match (true) {
            $quantity === 0 => 'out_of_stock',
            $quantity <= $reorderLevel => 'low_stock',
            default => 'in_stock',
        };
    }

    protected function paymentArray($payment): array
    {
        return [
            'id' => $payment->id,
            'idempotency_key' => $payment->idempotency_key,
            'provider' => $payment->provider,
            'provider_payment_id' => $payment->provider_payment_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'payment_method' => $payment->payment_method,
            'status' => $payment->status,
            'qr_payload' => $payment->qr_payload,
            'checkout_url' => $payment->checkout_url,
            'sale_id' => $payment->sale_id,
            'failure_reason' => $payment->failure_reason,
            'paid_at' => $payment->paid_at?->toISOString(),
            'items' => $payment->relationLoaded('items') ? $payment->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'sku' => $item->product?->sku,
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->values()->all() : [],
        ];
    }

    protected function saleArray($sale): array
    {
        return [
            'id' => $sale->id,
            'idempotency_key' => $sale->idempotency_key,
            'status' => $sale->status,
            'total_amount' => $sale->total_amount,
            'currency' => $sale->currency,
            'completed_at' => $sale->completed_at?->toISOString(),
            'items' => $sale->relationLoaded('items') ? $sale->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'sku' => $item->product?->sku,
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ])->values()->all() : [],
        ];
    }

    protected function transactionArray($transaction): array
    {
        return [
            'id' => $transaction->id,
            'payment_id' => $transaction->payment_id,
            'sale_id' => $transaction->sale_id,
            'type' => $transaction->type,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'provider_event_id' => $transaction->provider_event_id,
            'metadata' => $transaction->metadata,
            'occurred_at' => $transaction->occurred_at?->toISOString(),
        ];
    }
}
