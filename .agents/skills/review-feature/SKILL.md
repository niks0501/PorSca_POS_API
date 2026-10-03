---
name: review-feature
description: Review a current change independently against its requirements, risks, and verification evidence.
---

# Review Feature

Use after implementation or when the user asks for a code review.

Start from:
- goal;
- acceptance criteria;
- diff;
- changed files;
- verification evidence.

Expand context only around concrete concerns.

## Review priority

1. requirement coverage;
2. correctness;
3. security/authorization;
4. data integrity;
5. compatibility;
6. errors/edge cases;
7. concurrency/idempotency;
8. meaningful test gaps;
9. plausible performance regressions;
10. material maintainability problems;
11. accidental scope.

## Finding quality

Each finding must contain:
- severity;
- affected area;
- realistic failure scenario;
- evidence;
- smallest reasonable correction.

Do not invent findings.
Do not report style preferences as defects.
Do not approve from test status alone.

If no actionable defect exists, say:
`No actionable findings.`

Then state residual verification gaps.
