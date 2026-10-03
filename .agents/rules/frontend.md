# Frontend

- Preserve established component, routing, state-management, and styling conventions.
- Keep server state and local UI state conceptually separate.
- Avoid unnecessary global state.
- Handle loading, empty, success, error, and disabled states intentionally.
- Preserve keyboard navigation and semantic accessibility.
- Use correct labels, roles, focus behavior, and readable contrast.
- Avoid layout shifts and expensive renders when practical.
- Do not memoize reflexively; optimize from evidence or clear cost.
- Keep network calls cancellable or race-safe when stale responses can overwrite newer state.
- Treat user-generated content as untrusted.
- Do not expose secrets in client bundles.
- For forms, validate both client-side for UX and server-side for trust.
