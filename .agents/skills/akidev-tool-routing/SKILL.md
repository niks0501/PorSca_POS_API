---
name: akidev-tool-routing
description: Route UI design, unfamiliar, cross-cutting, debugging, runtime, and verification work to the smallest selected AkiDev capability; use shared CLIs without installing workstation tools.
---

<!-- Managed by AkiDev integration: akidev-tool-routing -->
# AkiDev tool routing

This API project declares these AkiDev integrations/capabilities: `laravel-boost,context7`.
Read `.akidev/config` as the source of truth.
Availability alone does not mandate use, and it never overrides an explicit request or authoritative project/global instruction.
When a trigger applies, follow that capability's documented transport or fallback rather than suppressing it because the integration is optional.

## Choose the smallest sufficient path

1. Start with direct source inspection, existing tests, and normal static checks.
2. Use a selected structural-analysis capability only for unfamiliar or cross-cutting impact questions; do not run Graphify on every task.
3. Use mobile runtime capabilities only after explicit user intent to inspect, reproduce, verify, or interact with a device, emulator, or running app.
4. For mobile verification policy, read `akidev-mobile-verification` once and follow its intent gate, operating modes, freshness, and evidence rules.
5. Use this repository's documented PHPUnit and Pint checks, then the API contract workflow when relevant.

## UI guidance and browser verification

For a selected `taste` integration, load `design-taste-frontend` for visual direction, aesthetics, landing pages, portfolios, and marketing redesigns.
For a selected `impeccable` integration, load `impeccable` for UI implementation refinement: layout, typography, interaction, consistency, audit, or polish.
Choose the skill matching the current phase; do not auto-load both, and do not apply marketing taste to native mobile or data-heavy product screens.
Existing frontend, accessibility, security, and stack rules still apply.
Firstmate owns orchestration; these skills supply guidance, not a new coordination framework.

For justified web UI verification, use the shared `chrome-devtools-axi` from PATH.
Its verified CLI provides `snapshot`, `screenshot`, `eval`, `console`, `network`, and `lighthouse`; inspect `--help` and command help for the installed version.
Use snapshots for accessible names/roles and keyboard checks, screenshots for visual evidence, and Lighthouse where supported for accessibility diagnostics.
These are not equivalent to the application's existing accessibility test suite; preserve and run app-owned tests when relevant.
Never install browser/a11y dependencies merely because AkiDev is selected.

## Shared prerequisite policy

Use `agent-device`, `adb`, and other selected shared commands from the active shell `PATH`.
If a prerequisite is missing, report it and use a safe static fallback where possible.
AkiDev does not install, upgrade, repair, wrap, or register workstation tools from the project.

## Android emulator preflight

For explicit React Native + Expo Android verification from WSL2, the developer starts and manages the Windows-hosted Android Studio emulator before development.
That emulator is the default Android verification device for agents running in WSL2.
Use the existing localhost ADB connection rather than changing host or ADB networking.
Before any Android device or E2E operation, run `adb devices -l`, discover the ready device at runtime, and prefer the emulator over a physical device.
If no usable emulator is listed, report a verification blocker instead of launching, recreating, wiping, reconfiguring, or repairing an AVD.
Do not reconfigure or repair ADB networking.
Do not set `ADB_SERVER_SOCKET`, start a second ADB server, or run `adb kill-server` in the healthy-connection path.
Use a physical device only when the task explicitly requires physical-device behavior.

## Explicit mobile intent and ownership

`akidev-mobile-runtime`, `akidev-mobile-verification`, `akidev-e2e-verification`, `agent-device`, and device-oriented ADB are explicit-user-intent capabilities.
Only one agent or worktree may actively control a given physical device or emulator.
The shared Windows-hosted emulator is mutable state, so route active device work through the designated serialized verification lane.
