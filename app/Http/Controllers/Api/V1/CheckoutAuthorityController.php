<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Checkout;
use App\Models\Payment;
use App\Models\ReconciliationCase;
use App\Services\CheckoutAuthority;
use App\Services\CheckoutOutcomes;
use App\Services\CheckoutReconciliation;
use App\Services\Payments\PayMongoSandboxGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CheckoutAuthorityController extends ApiController
{
    public function __construct(private readonly CheckoutAuthority $authority) {}

    public function session(Request $request)
    {
        return $this->data([
            'user' => ['id' => $request->user()->id, 'role' => $request->user()->role, 'isActive' => $request->user()->is_active],
            'storeId' => config('checkout.store_id'), 'environment' => app()->environment(),
            'contractVersion' => 'porsca-mobile-api-v3',
            'simulationAllowed' => $request->user()->role === 'admin' && app()->environment('staging')
                && config('checkout.simulation_enabled') && config('services.paymongo.mode') === 'sandbox',
        ]);
    }

    public function attempts(Checkout $checkout)
    {
        $this->scope($checkout);

        return $this->data(['items' => $this->checkoutArray($checkout)['attempts']]);
    }

    public function attemptShow(Checkout $checkout, Payment $attempt)
    {
        $this->scope($checkout);
        abort_unless($attempt->checkout_id === $checkout->id, 404);

        return $this->data(collect($this->checkoutArray($checkout)['attempts'])->firstWhere('id', $attempt->id));
    }

    public function reservation(Checkout $checkout, Payment $attempt)
    {
        $this->scope($checkout);
        abort_unless($attempt->checkout_id === $checkout->id, 404);
        $serialized = collect($this->checkoutArray($checkout)['attempts'])->firstWhere('id', $attempt->id);

        return $this->data(['checkoutId' => $checkout->id, 'attemptId' => $attempt->id, 'reservation' => $serialized['reservation']]);
    }

    public function index(Request $request)
    {
        $page = Checkout::where('store_id', config('checkout.store_id'))
            ->where(fn ($query) => $query->whereNotIn('state', ['completed', 'abandoned'])
                ->orWhereHas('cases', fn ($cases) => $cases->where('state', '!=', 'resolved')))
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return $this->data(['items' => $page->map(fn ($checkout) => $this->checkoutArray($checkout))->all(), 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function store(Request $request)
    {
        $key = $this->key($request);
        $input = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.productId' => ['required', 'integer', $this->integerRule(), 'min:1'],
            'items.*.quantity' => ['required', 'integer', $this->integerRule(), 'min:1', 'max:10000'],
        ]);
        $checkout = $this->authority->create($key, $input['items'], $request->user());

        return $this->data($this->checkoutArray($checkout), $checkout->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Checkout $checkout)
    {
        $this->scope($checkout);

        return $this->data($this->checkoutArray($checkout));
    }

    public function recover(Request $request, Checkout $checkout)
    {
        $this->scope($checkout);

        return $this->data($this->checkoutArray($this->authority->recover($checkout, $request->user())));
    }

    public function revalidate(Request $request, Checkout $checkout)
    {
        $this->scope($checkout);

        return $this->data($this->checkoutArray($this->authority->revalidate($checkout, $request->user())));
    }

    public function abandon(Request $request, Checkout $checkout)
    {
        $this->scope($checkout);
        $input = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);

        return $this->data($this->checkoutArray($this->authority->abandon($checkout, $input['reason'], $request->user())));
    }

    public function cash(Request $request, Checkout $checkout)
    {
        return $this->tender($request, $checkout, 'cash');
    }

    public function attempt(Request $request, Checkout $checkout)
    {
        return $this->tender($request, $checkout, 'qrph');
    }

    private function tender(Request $request, Checkout $checkout, string $kind)
    {
        $this->scope($checkout);
        $key = $this->key($request);
        $rules = [
            'revision' => ['required', 'integer', $this->integerRule(), 'min:1'],
            'acceptedAmountCentavos' => ['required', 'integer', $this->integerRule(), 'min:0', 'max:4294967295'],
        ];
        if ($kind === 'cash') {
            $rules['cashReceivedCentavos'] = ['required', 'integer', $this->integerRule(), 'min:0', 'max:4294967295'];
        }
        [$result, $created] = $this->authority->tender($checkout, $key, $kind, $request->validate($rules), $request->user());

        return $this->data($this->checkoutArray($result), $created ? 201 : 200);
    }

    public function refresh(Request $request, Checkout $checkout, Payment $attempt, CheckoutOutcomes $outcomes)
    {
        $this->scope($checkout);
        abort_unless($attempt->checkout_id === $checkout->id && $attempt->payment_method === 'qrph', 404);
        $outcomes->refresh($attempt, $request->user());

        return $this->data($this->checkoutArray($checkout->fresh()));
    }

    public function simulation(Request $request, Checkout $checkout, Payment $attempt, PayMongoSandboxGateway $gateway)
    {
        // Authorization is deliberately separate from all operator read resources.
        abort_unless(app()->environment('staging') && config('checkout.simulation_enabled') && config('services.paymongo.mode') === 'sandbox', 403);
        $this->scope($checkout);
        abort_unless($attempt->checkout_id === $checkout->id && $attempt->payment_method === 'qrph', 404);
        $capability = $gateway->simulationCapability($attempt);
        DB::transaction(function () use ($checkout, $attempt, $request): void {
            $this->authority->event($checkout, 'simulation_retrieved', $request->user(), [], $attempt->id);
        });

        return $this->data(['checkoutId' => $checkout->id, 'attemptId' => $attempt->id, 'provider' => 'paymongo', 'url' => $capability])
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function cases(Request $request)
    {
        $page = ReconciliationCase::whereIn('checkout_id', Checkout::where('store_id', config('checkout.store_id'))->select('id'))
            ->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return $this->data(['items' => $page->map(fn ($case) => $this->caseArray($case))->all(), 'pagination' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }

    public function caseShow(Request $request, ReconciliationCase $case)
    {
        $checkout = Checkout::findOrFail($case->checkout_id);
        $this->scope($checkout);
        $this->authority->event($checkout, 'case_viewed', $request->user(), [], $case->payment_id, $case->id);

        return $this->data($this->caseArray($case));
    }

    public function caseUpdate(Request $request, ReconciliationCase $case, CheckoutReconciliation $reconciliation)
    {
        $this->scope(Checkout::findOrFail($case->checkout_id));
        $input = $request->validate([
            'version' => ['required', 'integer', $this->integerRule(), 'min:1'],
            'action' => ['required', Rule::in(['review', 'close', 'reopen'])],
            'explanation' => ['required', 'string', 'min:20', 'max:2000'],
            'category' => ['required_if:action,close', Rule::in(['external_refund_confirmed', 'external_fulfillment_confirmed', 'other_verified_remedy'])],
            'evidenceClass' => ['required_if:action,close', Rule::in(['provider_verified', 'administrator_attested', 'staging_test'])],
            'evidenceReference' => ['required_if:action,close', 'string', 'min:8', 'max:500'],
            'externalReference' => ['required_if:category,external_refund_confirmed', 'string', 'min:8', 'max:500'],
            'attested' => ['exclude_unless:action,close', 'required', 'accepted'],
            'remedyCompleted' => ['exclude_unless:action,close', 'required', 'accepted'],
            'evidenceContradictory' => ['exclude_unless:action,close', 'required', 'declined'],
        ]);

        return $this->data($this->caseArray($reconciliation->update($case, $input, $request->user())));
    }

    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key)) {
            throw new ApiException('A 1–128 character Idempotency-Key header is required.', 422);
        }

        return $key;
    }

    private function integerRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value)) {
                $fail('The '.$attribute.' field must be a JSON integer.');
            }
        };
    }

    private function scope(Checkout $checkout): void
    {
        abort_unless($checkout->store_id === (string) config('checkout.store_id'), 404);
    }

    private function checkoutArray(Checkout $checkout): array
    {
        $checkout->load('attempts.items', 'sale', 'cases');
        $attempts = $checkout->attempts->sortBy('id')->map(function ($attempt): array {
            $holding = $attempt->status === Payment::PENDING && $attempt->reservation_expires_at?->isFuture();

            return [
                'id' => $attempt->id, 'method' => $attempt->payment_method,
                'amountCentavos' => $attempt->amount, 'currency' => $attempt->currency,
                'status' => $attempt->verification_state, 'financialStatus' => $attempt->status,
                'firstVerifiedOutcome' => $attempt->first_verified_outcome, 'createdBy' => $attempt->created_by,
                'items' => $attempt->items->map(fn ($item) => ['productId' => $item->product_id, 'quantity' => $item->quantity, 'unitPriceCentavos' => $item->unit_price])->all(),
                'providerReference' => $attempt->provider_payment_id,
                'qrPayload' => $attempt->qr_payload, 'qrExpiresAt' => $attempt->qr_expires_at?->toISOString(),
                'qrDurationSeconds' => $attempt->qr_seconds,
                'reservation' => $attempt->payment_method === 'cash' ? null : [
                    'state' => $holding ? 'held' : ($attempt->status === Payment::PENDING && $attempt->reservation_expires_at?->isPast() ? 'expired' : 'released'),
                    'expiresAt' => $attempt->reservation_expires_at?->toISOString(), 'durationSeconds' => $attempt->hold_seconds,
                ],
            ];
        })->values()->all();
        $sale = $checkout->sale;

        return [
            'id' => $checkout->id, 'storeId' => $checkout->store_id, 'state' => $checkout->state,
            'revision' => $checkout->revision, 'amountCentavos' => $checkout->amount_centavos, 'currency' => 'PHP',
            'items' => $checkout->items, 'createdBy' => $checkout->created_by,
            'createdAt' => $checkout->created_at?->toISOString(), 'abandonedAt' => $checkout->abandoned_at?->toISOString(),
            'history' => $checkout->events()->whereNull('case_id')->where('actor_type', 'operator')
                ->where('type', '!=', 'provider_observed')->orderBy('id')->get()->map(fn ($event) => [
                    'id' => $event->id, 'type' => $event->type, 'actorId' => $event->actor_id,
                    'evidence' => $event->evidence, 'occurredAt' => $event->occurred_at->toISOString(),
                ])->all(),
            'attempts' => $attempts,
            'sale' => $sale === null ? null : [
                'id' => $sale->id, 'method' => $sale->payment_method, 'amountCentavos' => $sale->total_amount,
                'cashReceivedCentavos' => $sale->cash_received, 'changeAmountCentavos' => $sale->change_amount,
                'completedAt' => $sale->completed_at?->toISOString(),
            ],
            // Markers only, even for administrators. Case details use the separate role-protected surface.
            'exceptions' => $checkout->cases->map(fn ($case) => ['caseId' => $case->id, 'reason' => $case->reason, 'state' => $case->state])->all(),
        ];
    }

    private function caseArray(ReconciliationCase $case): array
    {
        return [
            'id' => $case->id, 'checkoutId' => $case->checkout_id, 'attemptId' => $case->payment_id,
            'state' => $case->state, 'reason' => $case->reason, 'version' => $case->version, 'resolution' => $case->resolution,
            'providerHistory' => Checkout::findOrFail($case->checkout_id)->events()->where('payment_id', $case->payment_id)
                ->where('type', 'provider_observed')->orderBy('id')->get()->map(fn ($event) => [
                    'id' => $event->id, 'actorType' => $event->actor_type, 'actorId' => $event->actor_id,
                    'evidence' => $event->evidence, 'occurredAt' => $event->occurred_at->toISOString(),
                ])->all(),
            'history' => $case->events()->orderBy('id')->get()->map(fn ($event) => [
                'id' => $event->id, 'type' => $event->type, 'actorType' => $event->actor_type, 'actorId' => $event->actor_id,
                'evidence' => $event->evidence, 'occurredAt' => $event->occurred_at->toISOString(),
            ])->all(),
        ];
    }
}
