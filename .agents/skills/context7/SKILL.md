---
name: context7
description: Retrieve current, version-sensitive library documentation through the project-local Context7 MCP server or the pinned Context7 CLI when MCP is unavailable. Use when external package or API behavior may have changed since model training.
---

<!-- Managed by AkiDev integration: context7 -->

# Context7

Use Context7 when implementation depends on current external library or framework documentation and repository-local evidence is insufficient.

## How it is provided

AkiDev gives the active harness one of two Context7 access paths:

- **Pi, Codex and OpenCode** prefer the project-local Context7 MCP server (`.pi/mcp.json`, `.codex/config.toml`, and `opencode.json`/`opencode.jsonc`).
- **Pi and FirstMate workers without MCP** use the pinned CLI only when MCP tools are absent in their actual harness.
- Pi has native stdio/streamable HTTP support; no adapter is needed.
  Firstmate workers must run in the target project with its trusted configuration and MCP enabled by their owning harness.
  SDK-only workers need the harness's built-in MCP/codemode extensions, not an AkiDev adapter.
  Inspect available tools before choosing a fallback; never initialize a duplicate server or run `ctx7 setup`.

Both paths are intentionally pinned to the reviewed Context7 versions recorded by AkiDev in `tools/versions.env` (`CONTEXT7_MCP_VERSION` for the MCP server, `CTX7_VERSION` for the CLI). The CLI version is rendered into this skill at install time.

## Pi and FirstMate workers without MCP: query with the pinned `ctx7` CLI

When Context7 MCP tools are not available, fetch documentation with the pinned CLI without installing anything globally:

```bash
npx -y ctx7@0.5.12 library <name> "what you are trying to do"
npx -y ctx7@0.5.12 docs "<libraryId>" "your question"
```

Two-step flow:

1. **Resolve** the library name to its Context7 library ID:

   ```bash
   npx -y ctx7@0.5.12 library react "state management with hooks"
   ```

   `library` returns ranked matches with `Library ID` values in the form `/org/project` (for example `/reactjs/react.dev`), plus version-specific IDs when available (`/org/project/version`).

2. **Query** documentation with the full ID - IDs always start with `/`:

   ```bash
   npx -y ctx7@0.5.12 docs /reactjs/react.dev "how to clean up useEffect with async operations"
   ```

Options: `--json` prints structured output; `ctx7 --help`, `ctx7 library --help`, and `ctx7 docs --help` document the full reference. Output is clean when piped (no spinners or colors), so `... | head -50` or `... | grep` works.

Authentication: the CLI works without a key at IP-based rate limits. For higher quotas, Context7's current tooling reads a `CONTEXT7_API_KEY` (`ctx7sk_...` from https://context7.com/dashboard). Never put the key in committed configuration. If the CLI fails, report the gap and continue with repository evidence or other approved documentation sources.

## Rules

- Prefer repository facts and framework-owner documentation first.
- Use Context7 for version-sensitive third-party APIs and packages.
- Mention the dependency version when it materially affects the answer.
- Do not call Context7 reflexively for ordinary repository questions.
- Never place API keys or credentials in committed configuration.
- Never modify global Codex, OpenCode, or Pi configuration.
- If Context7 is unavailable (no MCP tools, or the CLI fails), report that gap and continue with repository evidence or other approved documentation sources.
