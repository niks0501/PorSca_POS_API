---
name: verification
description: Choose and execute evidence through the strict test ladder, including the E2E intent gate, without confusing unavailable checks with passes.
---

# Verification

## Strict ladder

Use this ladder in order when the changed behavior needs each level:

`static -> unit/component -> integration/Feature -> API/contract -> E2E`

Start at the lowest rung that can prove the changed behavior and stop when the acceptance criteria are met.
Do not run a higher rung merely for extra confidence when lower evidence is sufficient.
The target workflow is:

`feature implementation -> targeted test -> fast verification -> affected backend/contract checks -> E2E decision gate -> smallest justified E2E flow -> review`

Fast verification means the relevant static checks and unit/component tests.
Affected backend/contract checks mean integration/Feature and API/contract checks when those boundaries are changed or central to the claim.
A missing or unavailable higher rung is a verification gap only when its decision gate makes that rung necessary.

## E2E decision gate

E2E is exceptional evidence, not an automatic extra-confidence step.
E2E is allowed only for at least one of these reasons:

- browser-specific behavior;
- native/OS boundaries;
- critical multi-system journeys;
- release/staging acceptance;
- a regression that lower tests could not catch;
- an explicit user request.

When the gate is not met, E2E is not required for completion.
Normal web or mobile features should finish without Chrome, Metro, an Android emulator, Playwright, or Agent Device.
When the gate is met, choose the smallest flow that proves the specific claim rather than running a broad suite by habit.

## Retry and native blocker policy

Preserve a two-attempt ceiling for each justified deterministic Mode B E2E/replay flow: one initial attempt and at most one evidence-based retry.
This ceiling applies to deterministic flow attempts, not to individual interactive actions inside Mode A exploratory debugging.
Mode A should avoid pointless loops, but it may inspect, act, diagnose, fix, and continue from the current state without restarting the full journey after every failure.
Never blindly rerun a failed flow or use retries to compensate for missing diagnosis.
Biometrics, gallery pickers, permissions, and comparable native blockers are manual/native verification required conditions, not retry loops.
Record the blocker and finish the lower test levels instead of repeatedly fighting native dialogs.

## Mobile verification

For React Native and Expo, Jest/jest-expo plus React Native Testing Library is the ordinary completion path.
Agent Device is only a small representative native-boundary check after fast tests and only when the E2E decision gate justifies native evidence.
Ordinary tests must mock or abstract biometrics, gallery/image pickers, permissions, secure storage, notifications, and similar native APIs.
Prefer deterministic ADB/emulator mechanisms over repeated dialog fighting when a native-boundary check is justified.
Load `akidev-mobile-verification` before treating device or E2E results as valid evidence.
For newly implemented or changed mobile behavior whose successful path is not yet established, use Agent Device Mode A interactive verification first when the E2E decision gate justifies device evidence.
A relevant checked-in regression flow may use Mode B replay first as regression evidence.
Assess native-runtime freshness first so a stale development build is deferred instead of being mistaken for a feature failure.

## Principles

- Verification should match changed behavior and risk.
- Never invent commands.
- Never install missing tooling automatically.
- Never claim a skipped or unavailable check passed.
- Keep checks deterministic.
- For bug fixes, prefer a regression test.

## Report

For each command or check, report:

- command;
- result;
- what it proves;
- important gap.

Use `PASS`, `PASS_WITH_GAPS`, `FAIL`, or `INCOMPLETE`.
A passing check is evidence only for the claim it actually covers.
For device or browser checks, name the runtime and distinguish a skipped, unavailable, deferred, or stale check from a pass.
