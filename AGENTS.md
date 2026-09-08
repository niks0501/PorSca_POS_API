# PorSca API agent map

## What this repo is

- This repository is the PorSca Laravel 13 REST API.
- Laravel is the only backend and the source of truth for the store's API data and payment work.
- This repository has no frontend; the separate Expo mobile app is the only user-facing application.
- The API and mobile app are one release unit, even though they live in separate repositories.
- Project-specific rules live in the linked documents below, not in this map.

## Canonical check

Run the one required repository check:

```sh
composer verify
```

Use [docs/SETUP.md](docs/SETUP.md) for setup and prerequisites.

## Documentation map

- Setup — [docs/SETUP.md](docs/SETUP.md)
- Structure and API contract — [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Staging and promotion — [docs/STAGING.md](docs/STAGING.md)
- Testing — [docs/TESTING.md](docs/TESTING.md)
- Formal QA cycle — [docs/QA-CYCLE.md](docs/QA-CYCLE.md)
- Workflow, including the billing-outage merge rule — [docs/STAGING.md](docs/STAGING.md)

## Cross-repo handshake

- Contract: use `porsca-mobile-api-v1` only after verifying that name in the [API contract docs](docs/ARCHITECTURE.md) and the mobile repository's [API contract](https://github.com/alfredc-12/PorSca_POS/blob/staging/docs/API-CONTRACT.md); never rely on memory.
- Pair check: compare the exact full API and mobile commit SHAs in the current QA record with both repositories' `staging` tips, and confirm both contract documents name the same version.
- Promotion: promote the exact paired revisions from `staging` to `main` together, and only after a human approves the formal QA cycle.

## Boundaries

- Never merge or promote a release.
- Never approve a QA round; final QA approval belongs to a human.
- Secrets and webhook verification material stay server-side.
- Staging is the workbench; `main` is the shop window.

## Freshness

A PR that changes setup, contracts, or workflow must update the root agent guidance in that same PR. Keep `CLAUDE.md` as a thin pointer to `AGENTS.md`.
