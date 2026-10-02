# Engineering

## Design

- Solve the actual requirement, not hypothetical future requirements.
- Prefer explicit boundaries and data flow.
- Favor composition over inheritance when both are reasonable.
- Introduce abstractions only when they reduce real duplication or isolate meaningful variability.
- Avoid utility dumping grounds and giant service objects.
- Keep side effects visible.

## Correctness

- Define inputs, outputs, invariants, and failure behavior.
- Handle nullability, empty states, boundary values, time, ordering, retries, and partial failure where relevant.
- Make concurrency assumptions explicit when state can be modified concurrently.
- Preserve idempotency for operations that may be retried.

## Maintainability

- Names should describe domain intent.
- Functions and classes should have coherent responsibilities.
- Prefer local clarity over excessive indirection.
- Avoid broad refactors during narrow feature work unless required for correctness.
- Remove dead code created by the current change, but do not perform unrelated cleanup.

## Compatibility

- Identify public interfaces before changing them.
- Preserve API, schema, serialization, routes, and persisted-data compatibility unless breaking change is accepted.
- When deprecating behavior, provide a clear transition path.
