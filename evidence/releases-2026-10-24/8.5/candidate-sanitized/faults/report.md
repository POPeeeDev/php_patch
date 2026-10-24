# General memory-error and disruption tests

Status: **failures-found**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-sanitized | pass | 2903.085 |
| fault-sweep-sanitized | fail | 514734.416959 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
