# Backend

- Validate and normalize input at trust boundaries.
- Enforce authorization at the backend even when the UI hides actions.
- Keep domain rules separate from transport concerns when practical.
- Use transactions for multi-step state changes that must succeed atomically.
- Make retries and idempotency explicit for externally triggered operations.
- Avoid N+1 queries and unbounded data loading.
- Return stable error shapes for public APIs.
- Log useful operational context without leaking secrets or sensitive data.
- Apply timeouts and error handling to outbound network calls.
- Prefer framework-native security and lifecycle mechanisms over custom replacements.
