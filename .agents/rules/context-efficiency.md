# Context Efficiency

Use progressive context.

Start with the request, `AGENTS.md`, active skill or harness context, and focused repository evidence.

Expand only when a concrete question requires more context.

For tiny work, avoid architecture-wide scanning.
For normal work, inspect affected modules and nearest comparable patterns.
For high-risk work, inspect the relevant boundaries, contracts, data flows, security rules, and rollback paths.

An evidence-based review should begin from the task, diff, changed files, and verification evidence, then expand only around specific concerns.

Large generated files, lockfiles, vendored code, build output, old logs, and unrelated history should not be loaded unless directly relevant.
