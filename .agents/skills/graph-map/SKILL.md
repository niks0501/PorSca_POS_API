---
name: graph-map
description: Map repository structure, dependencies, and change blast radius using Graphify when already available or source inspection otherwise.
---

# Graph Map

Use for large, unfamiliar, cross-cutting, or architecture-sensitive work.

Do not edit files.

## Graphify

Graphify is optional.

If Graphify is already installed/configured and useful, it may accelerate discovery.

Never automatically:
- install/update it;
- register a project;
- add hooks;
- change global configuration.

If unavailable, use repository search, imports/references, LSP, configuration, routes, tests, and source files.

Verify important graph conclusions against source.

## Map

Identify:
- entry points;
- major relevant modules;
- call/data flow;
- persistence ownership;
- external services;
- cross-cutting auth/logging/events/queues;
- hidden dependencies;
- likely change blast radius.

Report the smallest useful map for the user's question.
