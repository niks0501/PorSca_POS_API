---
name: security-review
description: Perform a change-focused security review of trust boundaries, authorization, secrets, untrusted input, and abuse paths.
---

# Security Review

Use when the change affects:
- authentication;
- authorization;
- untrusted/public input;
- file uploads;
- secrets;
- webhooks;
- sensitive data;
- filesystem/network destinations;
- payments;
- security controls.

Do not perform heavyweight security review for unrelated trivial UI changes.

## Method

1. Identify assets and authority.
2. Identify attacker-controlled inputs.
3. Trace trust boundaries.
4. Verify authentication and server-side authorization.
5. Verify input validation/canonicalization.
6. Check injection/rendering/filesystem/URL risks.
7. Check secret/data exposure.
8. Check replay/rate/abuse/race paths where relevant.
9. Check framework-native security controls.
10. Verify tests for important denial/failure paths.

Findings must be concrete and tied to the change.
