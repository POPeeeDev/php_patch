# General memory-error and disruption tests

Status: **tested-scoped-cases**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-sanitized-upstream | pass | 1550.523 |
| fault-sweep-sanitized-upstream | pass | 277627.155271 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
