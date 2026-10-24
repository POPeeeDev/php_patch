# General memory-error and disruption tests

Status: **tested-scoped-cases**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-recoverable | pass | 1691.476 |
| fault-sweep-recoverable | pass | 44877.706229 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
