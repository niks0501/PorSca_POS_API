# Database

- Understand the current schema and query patterns before changing persistence.
- Prefer database constraints for invariants that must remain true regardless of application code.
- Use appropriate primary keys, foreign keys, unique constraints, and nullability.
- Add indexes from actual query/filter/join patterns, not by reflex.
- Consider index write cost and storage cost.
- Avoid unbounded table scans on expected large datasets.
- Use parameterized queries / ORM binding; never concatenate untrusted SQL.
- Treat migrations as production code.
- Plan backwards-compatible rollout when old and new application versions may overlap.
- Separate schema change from destructive cleanup when safer.
- Never assume a destructive migration is acceptable.
- For large tables, consider lock duration, rewrite behavior, online migration options, and backfill strategy.
- Verify timezone, precision, collation, case-sensitivity, and transaction semantics when they matter.
