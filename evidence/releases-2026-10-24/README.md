# Release matrix counts

These are observed outcomes, not a universal safety claim.

| Branch/profile | Variant | Passed | Skipped | Failed | Borked | Warned | Expected fail | Termination |
|---|---|---:|---:|---:|---:|---:|---:|---|
| 8.2 unpatched | release | 11012 | 7723 | 47 | 2 | 2 | 6 | completed |
| 8.2 official | release | 11040 | 7723 | 18 | 2 | 3 | 6 | completed |
| 8.2 candidate | release | 11034 | 7723 | 24 | 2 | 3 | 6 | completed |
| 8.2 official | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.2 candidate | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.3 unpatched | release | 11607 | 7994 | 51 | 2 | 1 | 7 | completed |
| 8.3 official | release | 11644 | 7994 | 14 | 2 | 1 | 7 | completed |
| 8.3 candidate | release | 11644 | 7994 | 14 | 2 | 1 | 7 | completed |
| 8.3 official | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.3 candidate | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.4 unpatched | release | 12511 | 8664 | 72 | 2 | 1 | 7 | completed |
| 8.4 official | release | 12561 | 8664 | 22 | 2 | 1 | 7 | completed |
| 8.4 candidate | release | 12561 | 8664 | 22 | 2 | 1 | 7 | completed |
| 8.4 official | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.4 candidate | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.5 unpatched | release | 14273 | 7900 | 96 | 2 | 1 | 8 | completed |
| 8.5 official | release | 14346 | 7900 | 23 | 2 | 1 | 8 | completed |
| 8.5 candidate | release | 14347 | 7900 | 22 | 2 | 1 | 8 | completed |
| 8.5 official | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |
| 8.5 candidate | sanitized | unknown | unknown | unknown | unknown | unknown | unknown | timeout |

[Full summary](summary.json). Each row directory retains its report, upstream statuses, and failure artifacts.
Timed-out rows have unknown final totals; recorded partial status counts are in the summary. Missing observations are not passes.
