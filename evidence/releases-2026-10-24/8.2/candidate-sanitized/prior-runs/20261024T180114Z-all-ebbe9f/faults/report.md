# General memory-error and disruption tests

Status: **tested-scoped-cases**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-sanitized | pass | 2699.239 |
| fault-sweep-sanitized | pass | 294820.777204 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
