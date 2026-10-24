# General memory-error and disruption tests

Status: **failures-found**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-upstream | pass | 1889.892 |
| fault-sweep-upstream | fail | 39488.768052 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
