# Durable checkout authority — mobile/API v3

## Version and scope

**Contract: `porsca-mobile-api-v3`. Base URL: `/api/v1`.** These new resources implement the authority-side foundation for Checkout Simulation v1. The earlier `porsca-mobile-api-v2` name is already used for the RBAC-era API; it is not reused for this incompatible logical-checkout surface. Existing catalog, inventory, authentication, sales history, and legacy cash/payment endpoints retain their documented v2 shapes. New money fields are explicitly integer PHP centavos (`amountCentavos`, `unitPriceCentavos`, `cashReceivedCentavos`, `changeAmountCentavos`); no floating-point money or peso-number coercion occurs here. Catalog's existing `price` field remains centavos.

All paths below are relative to `/api/v1`. Send `Authorization: Bearer <Sanctum token>`, `Accept: application/json`, and `X-PorSca-Contract-Version: porsca-mobile-api-v3`. Missing/wrong contract header on these resources returns `409`. Active-account and role enforcement happens server-side on every request. Login/logout remain the existing auth endpoints; neither a cached role nor device identity grants authority.

The single API client remains the mobile network boundary. PayMongo credentials, webhook material, provider calls, and financial/inventory authority stay in Laravel. No mobile changes are included here. **This is not yet a paired release or official PayMongo acceptance.** The mobile staging contract inspected during implementation still named v2. Pair only after the mobile client and both contract documents name v3 and the QA record identifies exact full mobile/API SHAs. Promote the exact pair only after human QA approval; see [STAGING.md](STAGING.md#checkout-v3-promotion-boundary).

## Resource map

Every successful response is `{ "data": ... }`; error envelopes remain [ARCHITECTURE.md](ARCHITECTURE.md#error-shape).

| Method | Path | Authority / result |
| --- | --- | --- |
| GET | `/checkout-session` | Current identity, role, active state, server store, environment, contract and capability eligibility; no simulator URL |
| POST | `/checkouts` | Establish server-assigned durable UUID, authoritative quote; idempotent |
| GET | `/checkouts` | Store unresolved discovery, newest first, paginated |
| GET | `/checkouts/{checkoutId}` | Stored checkout, snapshots, attempts, sale, history and read-only exception markers |
| POST | `/checkouts/{checkoutId}/recover` | Same durable state, with recovering operator attributed; no new attempt or provider I/O |
| POST | `/checkouts/{checkoutId}/revalidate` | Current products/prices/stock, new quote revision; only safe open/ready checkout |
| POST | `/checkouts/{checkoutId}/abandon` | Terminal safe abandonment, attributed reason |
| POST | `/checkouts/{checkoutId}/cash` | Authorized physical cash receipt assertion; atomic sale/ledger/deduction; idempotent |
| GET | `/checkouts/{checkoutId}/attempts` | `{ items: [...] }` stored attempts |
| POST | `/checkouts/{checkoutId}/attempts` | Start QR Ph attempt and finite hold; idempotent |
| GET | `/checkouts/{checkoutId}/attempts/{attemptId}` | Stored attempt, immutable amount/items, reservation and QR deadlines |
| GET | `/checkouts/{checkoutId}/attempts/{attemptId}/reservation` | `{ checkoutId, attemptId, reservation }` stored/effective hold state |
| POST | `/checkouts/{checkoutId}/attempts/{attemptId}/refresh` | Server provider inspection; no client-supplied outcome is used |
| POST | `/checkouts/{checkoutId}/attempts/{attemptId}/simulation-capability` | **Admin-only**, explicitly approved staging sandbox only, official capability when available |
| GET | `/reconciliation-cases` | **Admin-only** paginated cases for the server store |
| GET | `/reconciliation-cases/{caseId}` | **Admin-only**, detail/evidence/history; investigation read is attributed |
| PATCH | `/reconciliation-cases/{caseId}` | **Admin-only**, review/close/reopen with optimistic version check |

Store identity comes only from `CHECKOUT_STORE_ID` (single-store v1), not a client filter or a device. Multiple unresolved checkouts coexist; a pending customer does not block a distinct logical purchase. UUID fetches outside the configured store return `404`. List responses are `{ items, pagination: { current_page, last_page, total } }`, default 25, `per_page` clamped to 1–100. Checkout discovery includes non-completed/non-abandoned checkouts **and** terminal checkouts with unresolved cases; abandoned purchases remain terminal even when new contradictory evidence needs investigation. Do not infer nonexistence from an incomplete page. Retained records are not routinely purged.

## Checkout creation, quotes and tender

`POST /checkouts` requires an `Idempotency-Key` header and:

```json
{
  "items": [
    { "productId": 1, "quantity": 2 }
  ]
}
```

Product IDs/quantities must be JSON integers, positive; 1–100 lines, at most 10000 units per product. Duplicate product lines are combined and ordered by product ID. Client prices/totals are ignored. The server snapshots names, quantities and current PHP prices, validates available stock, and returns a durable checkout without reserving or deducting stock. The supported total is 0–4294967295 centavos; zero-total Cash is valid independently of QR eligibility.

New checkout returns `201`. Same creation key and normalized cart returns the original identity with `200`, including after completion/abandonment. Different cart with the same key returns `409`. A new purchase, even an identical basket, must use a new creation key.

Cash confirmation requires an `Idempotency-Key` and:

```json
{
  "revision": 1,
  "acceptedAmountCentavos": 1010,
  "cashReceivedCentavos": 2000
}
```

QR initiation requires an `Idempotency-Key` and:

```json
{ "revision": 1, "acceptedAmountCentavos": 1010 }
```

Amounts/revisions must be JSON integers, not strings, fractional numbers, exponent strings, signs, comma-formatted amounts or coerced empty values. Amounts are bounded to 0–4294967295. Cash below the authoritative amount returns `422` without effects. Insufficient stock returns `409`. Mobile cash text parsing/formatting and preset tender buttons are outside this API foundation.

Tender keys are scoped to a checkout and shared between Cash and QR: key reuse with a different method/revision/accepted amount/cash received returns `409`. Header grammar: 1–128 letters/digits or `._:-`; keep the same exact key on retries. Creation and tender key namespaces are independent. New tender returns `201`; replay returns `200`, the same attempt/sale and no new deduction. Internal provider operation keys are server-assigned and durable; clients never generate provider operations. A retry after failed QR provisioning reuses the same attempt, provider keys and original deadlines, never extends a hold.

Every new tender revalidates current products, prices and available inventory in the transaction. If prices/snapshot changed, tender returns `409` with `revalidationRequired`; call `/revalidate`, disclose the revised quote, and explicitly accept its new `revision` and `acceptedAmountCentavos`. A stale quote is never silently accepted. Revalidation/abandonment/new tender are all blocked while any prior attempt may still collect, when a checkout has a sale, or when payment/contradiction requires reconciliation. A different tender key does not bypass that lock.

`POST /abandon` requires `{ "reason": "Customer declined purchase" }` (8–500 characters). Any active operator may abandon a safe open/ready checkout. It cannot abandon pending/unknown, paid, completed or contradiction-locked checkouts. Abandonment records actor, reason and timestamp, does not erase attempts/history, and cannot be reversed into a new purchase.

## Response and independent states

Example pending checkout (history fields shortened):

```json
{
  "data": {
    "id": "server-assigned-uuid",
    "storeId": "porsca",
    "state": "payment_unresolved",
    "revision": 1,
    "amountCentavos": 1010,
    "currency": "PHP",
    "items": [{ "productId": 1, "name": "Coffee", "quantity": 2, "unitPriceCentavos": 505 }],
    "createdBy": 7,
    "createdAt": "2026-10-08T19:00:00.000000Z",
    "abandonedAt": null,
    "history": [],
    "attempts": [{
      "id": 12,
      "method": "qrph",
      "amountCentavos": 1010,
      "currency": "PHP",
      "status": "pending",
      "financialStatus": "pending",
      "firstVerifiedOutcome": null,
      "createdBy": 7,
      "items": [{ "productId": 1, "quantity": 2, "unitPriceCentavos": 505 }],
      "providerReference": "pi_provider_reference",
      "qrPayload": "data:image/png;base64,...",
      "qrExpiresAt": "2026-10-08T19:30:00.000000Z",
      "qrDurationSeconds": 1800,
      "reservation": { "state": "held", "expiresAt": "2026-10-08T19:30:00.000000Z", "durationSeconds": 1800 }
    }],
    "sale": null,
    "exceptions": []
  }
}
```

- Checkout states: `open`, `payment_unresolved`, `ready_for_attempt`, `completed`, `paid_unfulfilled`, `provider_contradiction`, `abandoned`.
- Attempt `status`: `pending`, `unknown`, `paid`, `non_payable`, `contradiction`. It is a verification state, **not** a sale-success flag. `financialStatus`: `pending`, `paid`, `paid_unfulfilled`, `failed`, `expired` for new resources. `firstVerifiedOutcome` is null, `paid`, or `non_payable`. Cash records a separate paid attempt plus the sale in the same transaction.
- Reservation: `held`, `expired`, `released`; Cash has null reservation/QR fields. Unknown attempts still hold stock until the original reservation deadline. Effective hold expiry releases stock without writing a payment failure or unlocking tender. A terminal verified result releases the hold; hold and QR deadlines/durations are stored separately even though initially aligned.
- Sale, when fulfilled: `{ id, method, amountCentavos, cashReceivedCentavos, changeAmountCentavos, completedAt }`; QR cash/change fields are null. Success corresponds to committed sale/items/ledger/inventory effects, not display state.
- Operator `history` is chronological, attributed creation/recovery/revalidation/tender/provision/abandonment/capability-access events. It excludes case detail and provider-evidence history. Attempt item snapshots preserve each old attempt's agreed quantities/prices after later revalidation.
- Exceptions are **read-only markers** `{ caseId, reason, state }` for both roles. Only the separate admin routes reveal explanation, remedy evidence and full provider history.

GETs and recovery never query the provider or upgrade pending to paid. Provider refresh records the acting operator; webhooks are attributed to the provider; automatic case creation/reopening is attributed to the system. Recovery does not modify the original attempt, prices, hold deadlines, outcome, or sale and does not depend on the original operator/device remaining active.

On the **first failed verification** (transport/timeout/5xx/malformed/mismatched/unverified or insufficiently correlated terminal response), an unresolved attempt becomes `unknown`, keeps `financialStatus: pending`, and blocks Cash. A later authoritative pending response returns it to `pending`. Neither is terminal. No client status, local countdown, hold deadline, simulator success page, or generic failed PaymentIntent establishes paid/non-payable.

The first verified financial outcome stands. A later conflicting verified paid/non-payable outcome is preserved as new provider evidence, marks the attempt `contradiction`, locks checkout tender, and opens/escalates a case. Existing sale, inventory and original financial outcome are untouched. An abandoned checkout remains abandoned with an exception marker. Fresh contradiction evidence after case closure reopens the case with a new version and retained prior resolution.

Late verified paid after hold release **must** fulfill the original attempt snapshot if stock is sufficient after respecting other holds, no sale exists, no other payable attempt is active, and the checkout is not abandoned/exception-locked. Otherwise payment becomes `paid_unfulfilled`, no ordinary sale/deduction is created, and reconciliation is required. A stock failure cannot label received money as failed. Duplicate webhook/refresh converges on the same sale, ledger and deduction. Pure `App\Domain\CheckoutRules` classifies evidence and enforces money/tender/fulfillment rules without I/O. Database uniqueness on `sales.checkout_id` is the final at-most-one-sale guard; shared product/inventory/checkout/payment locking and current reservation reads prevent race-based overselling.

## Reconciliation evidence

Case detail: `{ id, checkoutId, attemptId, reason, state, version, resolution, history, providerHistory }`. States: `open`, `under_review`, `resolved`, `reopened`. `history` preserves every case action and resolution; `providerHistory` includes first and contradictory observations with event/provider/resource references, verification class, actor and timestamp.

Every PATCH requires integer `version` and `action` (`review`, `close`, `reopen`) plus an explanation (20–2000 characters). Stale concurrent updates return `409`. Review cannot overwrite a resolved case; reopening requires a resolved case and retains its old resolution/history. Closure additionally requires:

```json
{
  "version": 1,
  "action": "close",
  "category": "external_refund_confirmed",
  "explanation": "Externally completed refund verified against the named record.",
  "evidenceClass": "administrator_attested",
  "evidenceReference": "refund-ledger-20261008-entry-42",
  "externalReference": "bank-refund-reference-42",
  "attested": true,
  "remedyCompleted": true,
  "evidenceContradictory": false
}
```

Categories: `external_refund_confirmed`, `external_fulfillment_confirmed`, `other_verified_remedy`. References are 8–500 characters. External refund additionally requires `externalReference`. Missing/insufficient evidence, uncompleted remedy, or contradictory evidence returns `422` leaving the case unchanged. V1 accepts accountable administrator attestation; the API does not independently validate the external remedy just because an administrator attests to it.

Evidence classes are `administrator_attested`, `staging_test`, and reserved `provider_verified`. `staging_test` requires explicitly approved staging. `provider_verified` closure is rejected (`422`) until an external-remedy verifier is integrated; the server must not relabel human attestation as provider verification. Verified QR observation evidence remains distinguishable in `providerHistory`. Resolution stores category, explanation, class, references, actor, attestation and timestamp.

Closure/reopening never refunds, changes provider status, alters inventory, creates a sale, erases financial exceptions, or unlocks tender on a paid/contradiction checkout. A resolved case does not turn an unfulfilled payment into a sale.

## Configuration and provider gates

| Setting | Default / meaning |
| --- | --- |
| `CHECKOUT_STORE_ID` | `porsca`, server-owned single-store scope |
| `CHECKOUT_HOLD_SECONDS` | 1800; must match QR lifetime for new attempts |
| `PAYMONGO_QR_EXPIRY_SECONDS` | 1800; 60–9000, stored per attempt and used for provider creation |
| `CHECKOUT_QR_MINIMUM_CENTAVOS` | 100, **provisional staging minimum**, not a discovered PayMongo account limit; Cash unaffected |
| `CHECKOUT_SIMULATION_ENABLED` | false; separate explicit staging approval gate |
| `CHECKOUT_PROVIDER_FINALITY_ENABLED` | false; attempt-correlated paid adapter gate, not authorization to skip provider validation |

GATE-01..06 remain **open**: official simulation support, attempt-specific finality/non-payability, supported outcomes, QR validity/account limits, delayed observation/recovery, and sandbox isolation. Operator-owned sandbox credentials and a stable webhook endpoint are still required. Deterministic Feature/MySQL tests prove the API authority seam and security boundaries, **not** actual PayMongo feasibility/acceptance.

The current sandbox adapter never establishes non-payability from generic intent failure or a QR/hold timer, including signed `qrph.expired` notifications for linked v3 attempts. Without a validated attempt-specific non-payability protocol, tender remains locked. The internal gateway inspection seam carries `verified`, `attempt_specific`, `non_payable`, `status`, and `resource_id`; tests provide explicitly correlated evidence. There is no HTTP endpoint to inject those fields. Extending official non-payability support requires GATE-02 evidence and adapter/contract tests, not a cashier/admin override.

The paid adapter is fail-closed unless explicitly enabled and a provider GET matches immutable intent ID, PHP amount, currency, sandbox mode, stored method ID, and stored payment resource ID. Do not enable it until the account's attempt correlation is validated. Local practice fixtures cannot settle as paid or yield an official capability.

Simulation retrieval additionally requires an active administrator and `APP_ENV=staging`, explicit enablement, `PAYMONGO_MODE=sandbox`, and server-only test credentials. It re-fetches the matching intent, checks amount/currency/test mode/method, and returns only the official HTTPS `data.attributes.next_action.code.test_url`, otherwise `503`. Response is `no-store, private`. That field's availability is still GATE-01 work, not a guarantee. The URL is not stored, included in cashier payloads/receipts/general history/logs, or used to fabricate outcomes; retrieval audit stores only actor/attempt/time. Non-staging or cashier requests return `403` without provider I/O/disclosure; inactive/unauthenticated requests return `401`. AC-34 covers PorSca's retrieval surface, not access control on a provider URL transferred externally.

## Migration and verification

The additive migration creates checkouts, operations, audit events and reconciliation cases; links new payments/sales through nullable fields. Historical v2 records remain unchanged with unavailable new fields rather than fabricated identities/attribution. Run migration and matching code together with checkout/payment writers stopped. Automatic rollback is refused if any v3 checkout exists; archival/reconciliation is an operator responsibility.

`CheckoutAuthorityTest` exercises this HTTP contract, including denial paths, provider failure/finality, recovery, evidence closure and signed webhook convergence. `CheckoutAuthorityConcurrencyTest` uses independent MySQL HTTP workers under `REPEATABLE READ`, held product-lock barriers, and no global PROCESS privilege: competing Cash keys, duplicate Cash/QR keys, duplicate verified paid refreshes, and concurrent case updates with a shared expected version. Provider calls are asserted outside transactions. See [TESTING.md](TESTING.md). Required repository check: `composer verify`.
