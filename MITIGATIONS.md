# Comparison with published PHP guidance

Source review date: 24 October 2026. The comparison concerns the published
guidance for CVE-2026-109618 and CVE-2026-109619.

The official releases containing the repairs are PHP 8.2.35, 8.3.36, 8.4.27, and
8.5.12. The experimental candidate uses a later development snapshot already
containing those repairs. Its measured comparison is therefore an addition to
patched behavior, not an alternative implementation of the entire vendor repair.

| Approach | Mechanism | Operational effect | Candidate comparison |
|---|---|---|---|
| Official runtime repair | Delays soft OOM to a safe point; uses hard termination at a higher threshold; resets request-owned state | Deployment and worker restart; retains memory limits and worker reuse | Candidate preserves this behavior and adds selected cleanup checks |
| `pm.max_requests=1` | Prevents a worker serving a later request with stale state from a previous request | Replaces workers after healthy requests as well as failures | Candidate reused workers in the tested healthy and soft-OOM scenarios; equivalent coverage is not established |
| Administrative `memory_limit=-1` | Removes the predictable finite PHP limit as an OOM trigger | Removes PHP's per-request memory ceiling; underlying allocation failure remains possible | Candidate retains finite limits through the already repaired upstream path |
| External container or cgroup memory bound | Contains aggregate memory use | A shared group kill may stop the master and multiple workers | Candidate's tested retirement affects the individual worker; external limits were not benchmarked |
| Disable upload progress or fatal-error backtraces | Removes specific demonstrated stale-state producers or consumers | Removes those features and requires worker restart | Candidate does not require those configuration changes; its selected checks do not establish equivalent coverage |
| Limit input complexity or block unused multipart requests | Reduces memory amplification and reachable input paths | Can reject uploads or complex submissions | Cleanup checks do not establish that input controls can be removed |

The [PHP OOM advisory](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w)
describes the repaired soft/hard behavior and the incomplete configuration
workarounds. It distinguishes process-group memory containment from limits such
as `RLIMIT_AS`, which can themselves induce allocation failure.

The [PHP stale-state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp)
describes the complete reset patch, feature-specific workarounds, and optional
one-request worker recycling. The feature settings are
`session.upload_progress.enabled=0` and, for PHP 8.5, `fatal_error_backtraces=0`.
The advisory identifies configuration workarounds as incomplete across all
application, extension, and server paths.

## Measured disruption

In an 81-request batch, one-request recycling produced 80 worker changes.
The recoverable candidate and upstream control each produced zero changes in
both the healthy and soft-OOM scenarios. All three configurations served every
unaffected request successfully. The strict control replaced 10 workers for
the 10 injected soft-OOM failures. Hard OOM still required worker replacement.

This measures worker churn in a local sequential FPM workload. Restart cost under
production concurrency, throughput, and latency guarantees were not established.

## Supported conclusions

| Statement | Evidence status |
|---|---|
| Lower churn than one-request recycling | Demonstrated in the local service test |
| Lower churn than the upstream repaired runtime | No difference in the tested scenarios |
| Added native cleanup enforcement | Implemented and exercised |
| Equivalent protection to all published mitigations | Not established |
| Additional prevention beyond the vendor repair | Not established |

The practical contribution is a testable, opt-in cleanup policy that preserves
the repaired runtime's service behavior. Its selected-root checks have a narrower
scope than the upstream reset of request-owned state.
