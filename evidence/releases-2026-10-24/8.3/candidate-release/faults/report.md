# General memory-error and disruption tests

Status: **tested-scoped-cases**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-recoverable | pass | 2995.81 |
| fault-sweep-recoverable | pass | 49053.59907 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
