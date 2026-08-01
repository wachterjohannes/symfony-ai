## Symfony Bridge

### Container Introspection

| Instead of...                  | Use                |
|--------------------------------|--------------------|
| `bin/console debug:container`  | `symfony-services` |

- Direct access to compiled container
- Environment-aware (auto-detects dev/test/prod)
- Supports filtering by service ID or class name via query parameter

### Profiler Access

When `symfony/http-kernel` is installed, profiler tools become available:

| Tool                        | Description                                             |
|-----------------------------|---------------------------------------------------------|
| `symfony-profiler-triage`   | Triage one request in a single call (queries, duplicates, slowest statements, duration, exception, log counts) |
| `symfony-profiler-list`     | List and filter profiles by method, URL, IP, status, date range |
| `symfony-profiler-get`      | Get profile by token                                    |
| `symfony-profiler-compare`  | Compare the collector summary of two profiles (before/after a change) |

**Resources:**
- `symfony-profiler://profile/{token}` - Full profile with collector list
- `symfony-profiler://profile/{token}/{collector}` - Collector-specific data

**Security:** Cookies, session data, auth headers, and sensitive env vars are automatically redacted.
