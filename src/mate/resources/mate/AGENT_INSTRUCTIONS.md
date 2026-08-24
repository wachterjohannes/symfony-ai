# AI Mate Agent Instructions

This file is managed by `mate discover`.
Run `##MATE_INVOCATION## discover` after installing, removing, or updating Mate extensions.

Mate exposes project-aware development tools through the `##MATE_INVOCATION##` CLI. Prefer these
tools over raw shell commands when they cover what you need:

- `##MATE_INVOCATION## tools:list`: list the available tools
- `##MATE_INVOCATION## tools:inspect <tool>`: show a tool's parameters and JSON input schema
- `##MATE_INVOCATION## tools:call <tool> --<param>=<value>`: run a tool
- `##MATE_INVOCATION## resources:read <uri>`: read a resource by URI

Add `--format=json` to the `tools:*`, `resources:read`, `debug:*`, `skills:list` and
`skills:validate` commands for machine-readable output.
