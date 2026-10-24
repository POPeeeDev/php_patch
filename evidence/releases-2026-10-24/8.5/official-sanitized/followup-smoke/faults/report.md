# General memory-error and disruption tests

Status: **failures-found**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-sanitized-upstream | pass | 3432.327 |
| fault-sweep-sanitized-upstream | fail | 444414.603691 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
