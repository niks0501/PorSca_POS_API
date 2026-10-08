<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Checkout;
use App\Models\ReconciliationCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckoutReconciliation
{
    public function update(ReconciliationCase $case, array $input, User $actor): ReconciliationCase
    {
        return DB::transaction(function () use ($case, $input, $actor): ReconciliationCase {
            // Settlement/escalation locks checkout before case; use the same order.
            Checkout::whereKey($case->checkout_id)->lockForUpdate()->firstOrFail();
            $case = ReconciliationCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->version !== $input['version']) {
                throw new ApiException('Case changed; retrieve its latest version.', 409);
            }
            $action = $input['action'];
            if ($action === 'close') {
                if ($case->state === 'resolved') {
                    throw new ApiException('Case is already resolved.', 409);
                }
                if ($input['evidenceClass'] === 'provider_verified') {
                    throw new ApiException('External remedy verification is not integrated; use an accurately labeled attestation.', 422);
                }
                if ($input['evidenceClass'] === 'staging_test' && (! app()->environment('staging') || ! config('checkout.simulation_enabled'))) {
                    throw new ApiException('Staging evidence requires approved staging.', 403);
                }
                $resolution = [
                    'category' => $input['category'], 'explanation' => $input['explanation'],
                    'evidenceClass' => $input['evidenceClass'], 'evidenceReference' => $input['evidenceReference'],
                    'externalReference' => $input['externalReference'] ?? null, 'attested' => true,
                    'actorId' => $actor->id, 'resolvedAt' => now()->toISOString(),
                ];
                $case->update(['state' => 'resolved', 'resolution' => $resolution, 'version' => $case->version + 1]);
            } else {
                if (($action === 'reopen' && $case->state !== 'resolved') || ($action === 'review' && $case->state === 'resolved')) {
                    throw new ApiException('Invalid reconciliation transition.', 409);
                }
                $case->update(['state' => $action === 'reopen' ? 'reopened' : 'under_review', 'version' => $case->version + 1]);
                $resolution = ['explanation' => $input['explanation']];
            }
            // No calls to settlement, refunds, sales or inventory here.
            Checkout::findOrFail($case->checkout_id)->events()->create([
                'case_id' => $case->id, 'payment_id' => $case->payment_id, 'actor_type' => 'operator', 'actor_id' => $actor->id,
                'type' => 'case_'.$action, 'evidence' => ['version' => $case->version, ...$resolution], 'occurred_at' => now(),
            ]);

            return $case;
        }, 3);
    }
}
