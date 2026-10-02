# Database Guide — MySQL

- Verify the actual MySQL/MariaDB version before relying on engine-specific behavior.
- Be deliberate about charset/collation and case sensitivity.
- Use InnoDB transactional semantics unless the project intentionally does otherwise.
- Verify index prefix/length behavior for large text/string columns when relevant.
- Understand `EXPLAIN` output for performance-sensitive queries.
- Be careful with implicit type conversion and SQL modes.
- Treat online DDL/locking behavior as version/operation specific.
- Prefer explicit UTC/timezone strategy.
- Use foreign keys/unique constraints for important invariants when architecture permits.
- Never concatenate untrusted SQL.
