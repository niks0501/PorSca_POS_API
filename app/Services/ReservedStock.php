<?php

namespace App\Services;

use App\Exceptions\InsufficientStock;
use App\Models\Inventory;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/** Call within a transaction, after locking inventory rows in ascending product order. */
class ReservedStock
{
    public function lockAndCheck(int $productId, int $quantity, string $name, ?int $ownPaymentId = null): Inventory
    {
        $inventory = Inventory::query()->where('product_id', $productId)->lockForUpdate()->first();
        $reserved = (int) DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payment_items.product_id', $productId)
            ->where('payments.status', Payment::PENDING)
            ->where('payments.reservation_expires_at', '>', now())
            ->when($ownPaymentId !== null, fn ($query) => $query->where('payments.id', '!=', $ownPaymentId))
            ->sum('payment_items.quantity');
        if ($inventory === null || $inventory->quantity < $quantity || $reserved > $inventory->quantity - $quantity) {
            throw new InsufficientStock($name);
        }

        return $inventory;
    }

    public function assertStockFloor(int $productId, int $newQuantity, string $name): void
    {
        $reserved = (int) DB::table('payment_items')
            ->join('payments', 'payments.id', '=', 'payment_items.payment_id')
            ->where('payment_items.product_id', $productId)
            ->where('payments.status', Payment::PENDING)
            ->where('payments.reservation_expires_at', '>', now())
            ->sum('payment_items.quantity');
        if ($newQuantity < $reserved) {
            throw new InsufficientStock($name);
        }
    }
}
