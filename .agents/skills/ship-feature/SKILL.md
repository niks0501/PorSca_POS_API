---
name: ship-feature
description: Implement a requested change using the lightest safe workflow, then verify and review it.
---

# Ship Feature

Use for normal implementation work.

## Workflow

1. Inspect Git state and preserve user work.
2. Establish goal/acceptance criteria.
3. Classify tiny/normal/high risk.
4. Inspect existing relevant patterns.
5. Plan proportionally.
6. Implement the smallest cohesive change.
7. Add/update tests when behavior changes.
8. Run narrow verification first.
9. Broaden verification when risk/surface justifies it.
10. Review the diff against acceptance criteria.
11. Report evidence, gaps, and unverified items.

## Escalate

Escalate to high-risk behavior for:
- auth/permissions;
- secrets;
- uploads;
- payments;
- migrations/destructive data;
- deployment/production config;
- public breaking contracts;
- broad architecture/security impact.

## Risky operations

Do not automatically:
- install/update dependencies;
- run destructive migrations;
- deploy/publish;
- commit/push/rebase/reset/stash;
- access secrets.

Obtain explicit approval when those actions are actually required.

A green check is not evidence for behavior it does not cover.
