# Release comparison findings

## Scope and attribution

Measured on macOS 26.7.1 (25G241), Intel x86_64, with Apple Clang 21.0.0.
These are POSIX non-thread-safe CLI, CGI, and FPM builds, not production deployments.
The exact affected inputs are 8.2.34, 8.3.35, 8.4.26, and 8.5.11. This does not
establish support for every older affected patch release.

The candidate applies the complete official maintenance update, verifies its
Git tree, then adds the three-file lifecycle guard. In notation, `C = V + G`:
the candidate is the official fixed release plus the guard. Vendor security
repairs must not be attributed to the additional guard. The complete update
also includes unrelated maintenance fixes.

Reproduction is documented in [BUILD.md](BUILD.md); mechanisms and limitations
are in [RELEASE_DESIGN.md](RELEASE_DESIGN.md). Acronyms and test terminology are
defined in [GLOSSARY.md](GLOSSARY.md).

## Normal-build comparisons

Each branch used the same official-release corpus for all three profiles.
No slow-test exclusion was enabled. These are the latest complete full-run
counts, not passing-only selections or counts reconstructed from follow-up tests.

| Branch | Unpatched passed / failed | Official passed / failed | Candidate passed / failed | Skipped in each row |
|---|---:|---:|---:|---:|
| 8.2 | 11,012 / 47 | 11,040 / 18 | 11,034 / 24 | 7,723 |
| 8.3 | 11,607 / 51 | 11,644 / 14 | 11,644 / 14 | 7,994 |
| 8.4 | 12,511 / 72 | 12,561 / 22 | 12,561 / 22 | 8,664 |
| 8.5 | 14,273 / 96 | 14,346 / 23 | 14,347 / 22 | 7,900 |

Every normal row also had two BORKED tests. Warnings and expected failures are
retained in the full reports; the table is not a claim that these suites passed.

- **8.3 and 8.4:** official and candidate adverse-status lists were identical.
- **8.2:** six additional candidate failures were System V message-queue tests.
  All six failed on both binaries in the sequential paired follow-up. The host
  had exhausted kernel IPC resources after repeated upstream tests. This does
  not establish a guard-specific regression or establish IPC safety.
- **8.5:** the sole differing case was `ext/standard/tests/file/bug36365.phpt`,
  a 70,000-file directory test. It timed out in the official full run and passed
  in the candidate full run. Both binaries passed the paired follow-up. No
  performance or memory-safety improvement is established by that difference.

Follow-ups are diagnostic evidence only: they do not erase original failures.
Other adverse outcomes include terminal/socket/IPv6 behavior, kernel IPC resource
exhaustion, and bounded-time failures. They remain visible rather than being
silently classified as successful tests.

### Tests changed by the recorded security commit

This is an analysis of the full normal-build runs, not a replacement test subset.
It includes every existing PHPT changed by the security commit recorded in each
release manifest. Fixed-output expectations can fail on old PHP without being
a demonstrated exploit or a memory-corruption detector.

| Branch | Commit PHPT files | Unpatched passed / failed | Official passed / failed | Candidate passed / failed | Recorded skips | Not individually recorded |
|---|---:|---:|---:|---:|---:|---:|
| 8.2 | 266 | 134 / 31 | 165 / 0 | 165 / 0 | 6 | 95 |
| 8.3 | 270 | 140 / 31 | 171 / 0 | 171 / 0 | 6 | 93 |
| 8.4 | 279 | 142 / 33 | 175 / 0 | 175 / 0 | 6 | 98 |
| 8.5 | 295 | 156 / 34 | 190 / 0 | 190 / 0 | 6 | 99 |

Not-recorded cases are not counted as passes. The build enables only the
documented extensions, and upstream discovery does not produce an individual
status for every file in the inventory.

## Service and native checks

The latest normal-build native/service checks passed for all four official and
candidate rows, including valid owned-alias cleanup, soft OOM, deterministic
mutation workloads, and the 384-case sampled allocation sweeps. Candidate
fixtures additionally checked the exported guard and dirty-checkpoint retirement.
The direct checkpoint fixture is not a naturally reached vulnerability chain.

Each FPM scenario used one sequential worker and 81 requests, with ten fault
requests in each fault scenario. All unaffected requests succeeded. Official
and candidate builds both recorded zero worker changes for healthy, soft-OOM,
and ordinary fatal-error scenarios, and ten changes for hard-limit failures.
There was no observed extra churn from the guard in these scenarios. No
production concurrency, throughput advantage, or latency guarantee was tested.

Raw native allocation failure uses `_exit(137)` in the candidate. This is
immediate retirement, not graceful cleanup: callbacks and buffered output may
be skipped. Application-level OOM retains the official repaired path.

## Instrumented outcomes

All eight instrumented builds compiled successfully. The resumed test rows are
still running; their final bounded outcomes and partial-coverage accounting are
not yet published. The normal-build counts above are not instrumented results.
Do not interpret this interim report as a completed sanitizer comparison.

### Investigated native differences

The PHP 8.5 debug allocation sweep found the same assertion in official and
candidate builds: callback workload, body-phase allocation ordinal 9,
`zif_array_map`, `ext/standard/array.c:6913`, condition `ret == SUCCESS`.
Both terminated with signal 6 / status 134. This is a reproduced debug assertion
under injected OOM, not demonstrated memory corruption or an additional CVE.
The guard does not prevent it. The normal builds do not enable that assertion.

On PHP 8.5 the candidate's five intentionally faulty detector probes emitted
their expected diagnostics but exceeded the 20-second bound while reporting.
A paired replay reproduced the difference with original reporting settings.
With ASan symbolization and UBSan stack traces disabled, all six checks completed
as expected on both binaries. This narrows the observed delay to the reporting
configuration; its underlying cause is not established. Detector sensitivity
is not evidence that the lifecycle guard prevents these injected errors.
The earlier PHP 8.3 official bounds-detector timeout did not recur in the paired
diagnostic replay with either reporting configuration.

The pinned upstream `--asan` runners enable tracked malloc, memory-limit
checking, and their own ASan/performance-sensitive skip conditions. OOM PHPT
timeouts are not explained by disabled memory limits. An earlier interim build
document stated otherwise; source inspection corrected that explanation.

## Harness corrections and retained evidence

Initial attempts exposed an older PHP 8.2 FastCGI client API, a cross-version
runner error-mask mismatch, and a five-second FPM startup deadline. The harness
now supports both client APIs, runs each upstream runner with its tested PHP,
and allows 30 seconds for FPM startup. Corrected reruns are retained separately.

The first PHP 8.2 candidate build unnecessarily regenerated vendor VM files
because patch application changed dependency timestamps. The builder now
normalizes tracked-file timestamps without changing content, verifies source
identity before building, and rejects tracked changes afterward. The initial
compiler error is retained, and the corrected candidate build completed.

Intermittent supervisor setup exits were recorded as failures or inconclusive
attempts, not PHP safety findings. Later runs use a per-run supervisor binary
with a recorded hash and more specific setup diagnostics. Reruns do not remove
earlier reports. Tests do not delete arbitrary host IPC resources or raise
kernel limits; use a disposable isolated test machine for full reproduction.

One launch failure was localized to `setpgid`: the supervisor exited 126 before
PHP ran. The setup now accepts an error only if the required private process
group already exists, otherwise it still fails closed. Both the earlier and
updated supervisors passed 1,000 subsequent launches and five process/exit/time
checks, so those stress runs alone do not establish the original race's cause.

An aggregation defect initially labeled the PHP 8.5 official sweep inconclusive
because a setup failure occurred alongside the definite assertion failure.
The corrected harness gives known failures precedence while retaining individual
inconclusive cases. Eight classification checks passed. Exported per-case
counts expose this distinction without rewriting the original report.

## Claims ledger

| Claim | Supported? | Boundary |
|---|---|---|
| Candidate builds from each of the four pinned affected inputs | Yes | Complete official update is a prerequisite stage |
| Selected native cleanup/retirement behavior is exercised | Yes | Valid-ownership and direct-checkpoint fixtures |
| Additional service churn was observed against the official fix | No | Only the local sequential scenarios measured |
| All upstream tests passed | No | Failures, skips, setup errors, and time limits remain |
| Guard independently fixes these CVEs or exceeds vendor protection | Not established | No additional exploit-prevention demonstration |
| Complete native PHP memory safety is proved | No | Dynamic tests and older bounded models do not prove all C execution |

Published mitigation comparisons are in [MITIGATIONS.md](MITIGATIONS.md).
Earlier 8.7 development results remain in [DEVELOPMENT_FINDINGS.md](DEVELOPMENT_FINDINGS.md)
and are not substituted for these release-branch measurements.
