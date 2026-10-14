<?php

namespace App\Domain;

use InvalidArgumentException;

/** Pure authority rules: no device, database, provider I/O or framework dependency. */
final class CheckoutRules
{
    public static function observation(array $inspection): string
    {
        if (($inspection['verified'] ?? false) !== true) {
            return 'unknown';
        }
        $status = $inspection['status'] ?? null;
        if ($status === 'pending') {
            return 'pending';
        }
        if (($inspection['attempt_specific'] ?? false) !== true) {
            return 'unknown';
        }
        if ($status === 'paid') {
            return 'paid';
        }
        if (in_array($status, ['failed', 'expired'], true) && ($inspection['non_payable'] ?? false) === true) {
            return 'non_payable';
        }

        return 'unknown';
    }

    public static function canTender(string $state, bool $hasSale, bool $hasBlockingAttempt): bool
    {
        return in_array($state, ['open', 'ready_for_attempt'], true) && ! $hasSale && ! $hasBlockingAttempt;
    }

    public static function canFulfill(string $state, bool $hasSale, bool $hasOtherPayableAttempt): bool
    {
        return ! in_array($state, ['abandoned', 'provider_contradiction', 'paid_unfulfilled'], true)
            && ! $hasSale && ! $hasOtherPayableAttempt;
    }

    public static function lineTotal(int $total, int $unitPrice, int $quantity): int
    {
        if ($total < 0 || $total > 4294967295 || $unitPrice < 0 || $quantity < 1
            || $quantity > intdiv(4294967295, max(1, $unitPrice)) || $total > 4294967295 - $unitPrice * $quantity) {
            throw new InvalidArgumentException('Amount exceeds supported integer centavo range.');
        }

        return $total + $unitPrice * $quantity;
    }
}
