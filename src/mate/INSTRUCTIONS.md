## Server Info

| Instead of...       | Use           |
|---------------------|---------------|
| `php -v`            | `server-info` |
| `php -m`            | `server-info` |
| `uname -s`          | `server-info` |

- Returns PHP version, OS, OS family, and loaded extensions in a single call

## Sandbox Execute

| Instead of...                                  | Use                |
|------------------------------------------------|--------------------|
| Calling a tool N times and averaging by hand    | `sandbox-execute`  |
| Asking for an aggregation tool that does not exist | `sandbox-execute` |

- Runs a short PHP snippet against a `$mate` interface and returns what it returns
- Strict AST allowlist (no `new`, no closures, no static access, no superglobals, a fixed
  list of pure functions) plus a locked-down subprocess
- `$mate->runCommand(string $command)` runs commands listed verbatim in
  `mate.sandbox.allowed_commands`; that list is empty by default, so nothing runs until a
  project opts in
- Read the `symfony-sandbox-execute` skill for the grammar before writing a snippet
