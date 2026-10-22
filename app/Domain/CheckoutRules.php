<?php

namespace App\Domain;

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
        return UnsignedIntegerBound::add($total, $unitPrice, $quantity);
    }
}
