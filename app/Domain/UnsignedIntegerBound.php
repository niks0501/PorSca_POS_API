<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * Canonical bound for values persisted to the API's unsigned 32-bit columns.
 *
 * Product prices, stock counts, sale and payment amounts, and ledger amounts use
 * MySQL UNSIGNED INT, so the supported range is 0 through 4294967295. Request
 * validation rejects larger values at the edge; computed amounts use these
 * helpers so overflow is refused before any database write.
 */
final class UnsignedIntegerBound
{
    public const MAX = 4294967295;

    public static function contains(int $value): bool
    {
        return $value >= 0 && $value <= self::MAX;
    }

    /**
     * Accumulate one line into an amount total without overflowing the column or
     * multiplying beyond the supported range first.
     */
    public static function add(int $total, int $unitPrice, int $quantity): int
    {
        if (! self::contains($total) || ! self::contains($unitPrice) || $quantity < 1
            || $quantity > intdiv(self::MAX, max(1, $unitPrice))
            || $total > self::MAX - $unitPrice * $quantity) {
            throw new InvalidArgumentException('Amount exceeds supported integer centavo range.');
        }

        return $total + $unitPrice * $quantity;
    }

    /** Return an already-validated difference, refusing a negative or over-range result. */
    public static function difference(int $minuend, int $subtrahend): int
    {
        if (! self::contains($minuend) || ! self::contains($subtrahend) || $subtrahend > $minuend) {
            throw new InvalidArgumentException('Amount exceeds supported integer centavo range.');
        }

        return $minuend - $subtrahend;
    }
}
