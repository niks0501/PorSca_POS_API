# Testing

Tests should prove behavior, not mirror implementation.

- Add or update tests when behavior, branching, validation, data transformation, error handling, state transitions, public contracts, or bug fixes change.
- Follow the strict ladder and stopping rules in the shared verification skill, preferring the lowest test level that provides strong evidence.
- Run targeted tests before fast verification, then run only affected integration/Feature or API/contract checks before considering the E2E decision gate.
- E2E is exceptional rather than automatic extra confidence; use it only when the verification skill's gate permits it.
- Keep a justified deterministic Mode B E2E/replay flow within the two-attempt ceiling, and classify biometrics, gallery pickers, permissions, and comparable native blockers as manual/native verification required instead of retry loops.
- The deterministic flow ceiling does not cap individual interactive Mode A actions; diagnose, fix, and continue from the current session instead of restarting after every failure.
- Ordinary tests must mock or abstract biometrics, image pickers, permissions, secure storage, notifications, and similar native APIs.
- Prefer deterministic ADB/emulator mechanisms over repeated dialog fighting when native verification is justified.
- Use integration tests when framework wiring, persistence, HTTP, filesystem, queues, or external boundaries are central to the behavior.
- Bug fixes should ideally include a regression test that fails before the fix and passes after it.
- Keep tests deterministic.
- Avoid arbitrary sleeps when event/state synchronization is available.
- Do not weaken tests to make a change pass.
- Treat flaky tests as defects or documented gaps, not as automatic retries forever.
- Do not claim broad coverage merely because the suite is green.
