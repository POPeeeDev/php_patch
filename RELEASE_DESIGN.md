# Release guard design

## Composition and attribution

Let U be an affected release, V its exact official maintenance release, and G
the additional three-file guard:

```text
C = apply(G, apply(diff(U, V), U)) = V + G
```

In words: apply the entire official update to the affected release, then apply
the guard. After the first step the full Git tree must equal the official tree.
The guard changes only `Zend/zend_alloc.h`, `Zend/zend_alloc.c`, and `main/main.c`.

The official update supplies the security repairs, generated VM code, broader
state cleanup, and regression tests, plus other maintenance-release changes.
`manifest.json` identifies its security commits. Upstream licenses are retained.
Comparing U with V measures all release differences; comparing V with C isolates
the guard. Credit for the official repairs belongs to the upstream PHP authors.

## Native operations

1. `clear_last_error()` takes the two owned error-string references, sets both
   global slots to NULL, and then releases the local references. If both slots
   legitimately own references to one string, both releases remain necessary.
2. `zend_oom_guard_check_request_state()` runs after that cleanup and before heap
   reclamation. It checks the two slots and, on PHP 8.5, the fatal-error backtrace
   slot. A dirty checkpoint retires the worker.
3. Raw nonfallible allocation failure calls `zend_oom_guard_retire()`, which uses
   `_exit(137)` without allocation, formatting, or application callbacks.

The guard does not replace soft-OOM handling, VM safe points, or the official
wider resets. Hard memory-limit handling remains upstream's diagnostic and exit.
Raw-allocation retirement is not graceful cleanup: shutdown callbacks and output
flushing can be skipped. This is not arbitrary-pointer/refcount validation.

The old development experiment's strict-retirement mode is not included. These
release patches always use the additive recoverable policy. Windows and
thread-safe builds are rejected at compilation.

## Evidence boundaries

| Observation | Supports | Does not establish |
|---|---|---|
| Exact tree after upstream patch | Official update reproduced | Correct binary behavior |
| Owned-alias fixture passes | Cleanup for valid ownership in that fixture | Arbitrary pointer/refcount safety |
| Dirty-checkpoint fixture exits 137 | Selected condition is enforced | A naturally reached CVE chain |
| Service recovery matches official | No extra churn in those scenarios | Production concurrency or throughput advantage |
| No additional adverse suite cases | No regression detected in that run | Complete equivalence or all-platform safety |
| Sanitizer diagnostic | A dynamic failure to investigate | Causation by this guard without a matched control |

The dirty fixture installs valid owned references and calls the checkpoint
directly. It is a unit fixture, not an exploit. No arbitrary-code-execution
payload is part of this benchmark. The older bounded TLA+ model is an abstraction,
not a proof of refinement to these C implementations or complete native safety.
