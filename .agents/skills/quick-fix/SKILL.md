---
name: quick-fix
description: Make a small local fix with minimal context, minimal scope, and targeted verification.
---

# Quick Fix

Use only when the change is genuinely isolated and low risk.

Good fit:
- one or two closely related files;
- known/obvious defect or tiny behavior change;
- no schema/public API/auth/dependency/deployment impact.

Do not force a complex issue into this workflow.

## Workflow

1. Inspect Git state.
2. Reproduce or establish the exact failure.
3. Inspect the focused code path and nearest test.
4. Identify root cause.
5. Make the smallest complete fix.
6. Add/update a regression test when behavior warrants it.
7. Run the narrowest meaningful check.
8. Inspect the final diff for accidental scope.
9. Report cause, change, evidence, and residual risk.

## Escalate when

- more systems/files are involved than expected;
- root cause remains uncertain;
- dependency/schema/API/auth/security/deployment behavior is involved;
- the fix would require an architectural workaround.

Do not add abstractions for hypothetical future reuse.
Do not hide unrelated failing checks.
