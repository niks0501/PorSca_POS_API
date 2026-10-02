---
name: plan-feature
description: Build an implementation-ready plan from repository evidence without editing files.
---

# Plan Feature

Use when the user wants implementation planning, architecture preparation, or a safe design before code changes.

Do not edit tracked project files.

## Workflow

1. Inspect repository/Git state read-only.
2. Establish goal and acceptance criteria.
3. Classify risk.
4. Inspect the nearest relevant implementation and tests.
5. Identify affected interfaces, data, and dependencies.
6. Load only relevant stack/rule documentation.
7. Produce the smallest implementation-ready plan.
8. Map verification to each behavior.

## Decision rules

- Resolve repository-discoverable facts before asking questions.
- Separate confirmed facts, assumptions, and recommendations.
- Prefer existing project patterns.
- Avoid speculative refactors.
- Include security/data/rollout detail only when relevant.
- For high-risk changes, include rollback/recovery thinking.

## Complete when

The plan identifies:
- scope/out-of-scope;
- current relevant flow;
- ordered changes;
- affected areas;
- important edge cases;
- contracts/data impact;
- risks;
- verification;
- unresolved decisions that truly require the user.

Never claim implementation or verification occurred.
