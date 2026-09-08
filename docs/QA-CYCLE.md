# Formal QA cycle

Formal QA is a paired mobile/API release check. A human gives the final approval. An agent must never approve or self-approve a release.

## Cycle record

Create one shared record before testing. Use one cycle ID in both repositories and every defect.

```yaml
cycle_id: porsca-qa-YYYY-MM-DD-NN
api_commit: <full API commit SHA>
mobile_commit: <full mobile commit SHA>
api_branch: staging
mobile_build: <tester build or exact run command>
seed_version: qa-baseline-2026-01
api_base_url: https://<stable-api-host>/api/v1
environment: staging
paymongo:
  mode: sandbox
  secret_context: <test key label or vault reference, never the key>
  webhook_context: <sandbox webhook label or vault reference>
  qrph: true
started_at: <UTC timestamp>
ended_at: <UTC timestamp>
qa_owner: <human name>
approval: pending
```

Record full SHAs, not branch names alone. Record the seed version printed by `qa:reset`. Record the PayMongo sandbox account/context and the QR Ph path used, but never record secret values.

## Start and preserve the cycle

1. A human chooses the cycle ID and QA owner.
2. Confirm `staging` contains the intended API commit and the paired mobile build identifies its exact commit.
3. Confirm the stable API URL returns `environment: staging`, `database: ok`, and `paymongo_mode: sandbox`.
4. Run `php artisan qa:reset --force` before testing and record `qa-baseline-2026-01`.
5. Run `composer verify` and the Postman collection. Keep their artifacts.
6. Test the mobile flow against the same URL and data.
7. Do not reset, reseed, or patch the database during the cycle.
8. A human reviews the result and records approval or rejection.

A reset is allowed only to begin a new cycle. A changed Funnel URL, API commit, mobile commit, seed, or PayMongo context starts a new cycle record.

## Defects

Capture each defect as a GitHub Issue. Put the cycle ID in the title and body. Use these fields in every issue:

```text
Cycle: <cycle ID>
Environment / URL: <staging name and stable URL>
Severity: Critical | High | Medium | Low
Requirement: <spec requirement or contract endpoint>
Steps:
1. <exact step>
2. <exact step>
Expected: <what should happen>
Actual: <what happened>
Evidence: <Postman request/report, Laravel test output, logs, or screenshot>
Fix: <PR URL or pending>
Retest: <human tester, date, result, and evidence>
```

Severity rules:

- **Critical:** data loss, duplicate charge/sale/deduction, secret exposure, or a blocked core checkout. Blocks approval.
- **High:** a core requirement is wrong or a major mobile/API flow cannot finish. Blocks approval.
- **Medium:** a non-core defect with a workaround. Does not block by itself.
- **Low:** copy, layout, or minor polish. Does not block by itself.

Do not close a defect until a human records a retest. If a fix changes the API or mobile commit, record the new exact pair and decide whether to start a new cycle.

## Defect summary and approval

Derive the Defect Summary from the cycle's issue query, not from memory:

```text
Cycle: <cycle ID>
Total: <all issues in this cycle>
Critical: <count>  High: <count>  Medium: <count>  Low: <count>
Open blockers: <Critical + High still open>
Fixed and retested: <count>
Not retested: <count>
```

The cycle can be approved only when the human QA owner confirms the exact paired commits were tested, all Critical and High issues are fixed and retested or explicitly rejected by the release authority, and the evidence is retained. The agent can prepare this record and report facts, but cannot fill `approval: approved`.

## Evidence retention

Keep the following for the agreed retention period:

- cycle record and full API/mobile commit SHAs
- CI URL and `composer verify` output
- Laravel test output
- Newman report and collection/environment files
- API health, checkout, payment, and webhook responses
- mobile test evidence and screenshots
- defect Issues and human retest notes

Redact bearer tokens, PayMongo secret keys, webhook verification material, and authorization headers before sharing evidence.
