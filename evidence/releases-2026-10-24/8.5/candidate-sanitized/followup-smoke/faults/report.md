# General memory-error and disruption tests

Status: **failures-found**. Not a general memory-safety proof.

| Check | Result | Wall ms |
|---|---|---:|
| aliased-roots-sanitized | pass | 1549.726 |
| fault-sweep-sanitized | fail | 295489.808611 |

[Full measurements, commands, limits and scope](report.json). Skips, expected failures, baseline failures and sanitizer allocator coverage must be reviewed separately.
