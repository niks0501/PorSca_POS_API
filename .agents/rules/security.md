# Security

Apply security effort proportional to the changed attack surface.

## Trust boundaries

Identify:
- untrusted user input;
- authentication state;
- authorization decisions;
- uploaded files;
- external webhooks/APIs;
- secrets and credentials;
- database writes;
- client/server boundaries.

## Baseline expectations

- Deny by default for authorization.
- Validate and canonicalize inputs.
- Use framework-native CSRF/XSS/session protections correctly.
- Prevent injection through parameterization and safe APIs.
- Never log or return secrets.
- Use secure password/token handling primitives.
- Treat filenames, MIME types, URLs, redirects, and archive contents as untrusted.
- Apply rate limits/abuse controls when exposure justifies them.
- Verify webhook authenticity before trusting payloads.
- Use least privilege for database/service credentials.

## Review behavior

Security review should focus on realistic failure paths created or modified by the change, not generic checklist noise.
