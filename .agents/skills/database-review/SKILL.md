---
name: database-review
description: Review schema, migrations, queries, constraints, transactions, indexes, and data-safety implications.
---

# Database Review

Use when a change modifies persistence, schema, migrations, query behavior, or important data invariants.

## Review

Check:
- data model and ownership;
- nullability/defaults;
- foreign keys and uniqueness;
- invariant enforcement;
- transaction boundaries;
- concurrency/race behavior;
- query plans/access patterns;
- N+1/unbounded queries;
- indexes justified by filters/joins/order;
- migration compatibility;
- destructive behavior;
- backfill strategy;
- large-table lock/rewrite risk;
- rollback limitations.

For MySQL/PostgreSQL, use the actual engine's semantics where they differ.

Never treat a migration as safe merely because it runs on an empty local database.
