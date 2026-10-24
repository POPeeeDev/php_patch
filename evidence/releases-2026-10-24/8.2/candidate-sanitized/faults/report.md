# General memory-error and disruption tests

Status: **tested-scoped-cases**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-sanitized | pass | 752.934 |
| fault-sweep-sanitized | pass | 201034.310736 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
