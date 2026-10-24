# Historical development-snapshot findings: 24 October 2026

These measurements concern PHP 8.7.0-dev, not affected release branches. Current
release-targeted measurements are documented separately in [FINDINGS.md](FINDINGS.md).

## Scope

The experiment compared an upstream control, a strict retirement control, and a
recoverable candidate built from one PHP 8.7.0-dev source snapshot. Release and
sanitizer builds used non-threaded command-line and FPM executables on macOS
x86_64. The complete six-row source-suite matrix finished within its suite bounds.
The recorded overall result is **failures found**.

Machine-readable measurements are in
[results.json](evidence/2026-10-24/results.json). Public package checks are recorded
separately in [package-validation.json](evidence/package-validation.json).

## Service recovery

A private Unix socket connected the test client to an actual FPM pool containing
one worker. Each scenario comprised 80 scheduled requests and a final recovery
probe. The soft-OOM, ordinary-fatal-error, and hard-OOM scenarios each injected
10 faults. Healthy traffic exercised arrays, garbage collection, sessions,
serialization, fibers, and JSON.

| Configuration | Healthy worker changes | Soft-OOM worker changes | Soft-OOM batch shutdown callbacks | Hard-OOM worker changes | Unaffected request failures, all scenarios |
|---|---:|---:|---:|---:|---:|
| Upstream | 0 | 0 | 81 | 10 | 0 |
| Strict retirement | 0 | 10 | 71 | 10 | 0 |
| Recoverable | 0 | 0 | 81 | 10 | 0 |
| Upstream with `pm.max_requests=1` | 80 | 80 | 81 | 80 | 0 |

The one-request configuration used 81 distinct workers in each 81-request batch;
there are 80 changes between consecutive recorded requests. The candidate reused
the worker after each tested soft OOM and retained shutdown callbacks. Its behavior
matched the upstream control. A soft-OOM request itself still failed.

This demonstrates fewer replacements than strict retirement or one-request
recycling. It does not establish equal security coverage between these mechanisms,
production throughput, concurrent-request behavior, or external transaction rollback.

## Full upstream discovery

The source inventory contains 23,655 PHPT files. The upstream runner selected tests
for the built extensions, retaining its unsupported-platform and offline skips.
The build tested 25 extensions; 49 extension trees were not built. No slow-test
exclusion or selected list of positive tests was used.

| Profile / allocator | Passed | Skipped | Failed | Borked | Warned | Expected failure |
|---|---:|---:|---:|---:|---:|---:|
| Upstream / Zend | 14,838 | 8,804 | 1 | 2 | 3 | 7 |
| Recoverable / Zend | 14,838 | 8,804 | 1 | 2 | 3 | 7 |
| Sanitized upstream / system | 14,740 | 8,892 | 10 | 2 | 4 | 7 |
| Sanitized recoverable / system | 14,743 | 8,892 | 8 | 2 | 3 | 7 |
| Sanitized upstream / Zend | 14,795 | 8,840 | 8 | 2 | 1 | 9 |
| Sanitized recoverable / Zend | 14,795 | 8,840 | 8 | 2 | 1 | 9 |

No candidate row added a FAIL, BORK, or LEAK result relative to its matched control.
[comparison.json](evidence/2026-10-24/comparison.json) lists the shared adverse
results. Fewer timeouts in one candidate run do not establish a performance fix.

### Unresolved failures

- Release runs shared one IPv6 multicast failure and two invalid test-setup
  results. The captured messages identify an invalid/default interface or missing
  route on the test host.
- Each sanitizer row reported six DNS-related tests with a UBSan misaligned
  pointer-load diagnostic in `ext/standard/dns.c`, at lines 273 or 305 in the
  pinned source. The same diagnostics occurred in both control and candidate.
- The system-allocator upstream run had three timed-out tests; the candidate had
  one. Each Zend-allocator sanitizer run timed out in `Zend/tests/new_oom.phpt`.
  The upstream runner labels these FAIL, but the safety interpretation is
  inconclusive. They are not counted as successful safety checks.
- Expected failures and warnings remain separately reported. In these runs,
  warnings include tests marked as expected failures that unexpectedly passed.

Normalized `.out` and `.diff` files are retained in the per-suite artifact folders.
Sharing a failure with the control does not make the failure acceptable or show
that the guard intercepted it.

## Allocation failures and detector controls

Each profile ran 384 injected-allocation cases: 192 startup cases and 192 cases
armed in the application body, spread across array, session, and callback workloads.
The sweep sampled 64 spaced allocation ordinals per workload and phase, using
recorded calibration bounds. Across five profiles this produced 1,920 executions.

| Profile | Passed / attempted |
|---|---:|
| Upstream | 384 / 384 |
| Strict | 384 / 384 |
| Recoverable | 384 / 384 |
| Sanitized upstream | 383 / 384 |
| Sanitized recoverable | 383 / 384 |

Both debug builds aborted at body allocation ordinal 9 in the callback workload:
`zif_array_map` asserted `ret == SUCCESS` at `ext/standard/array.c:7111`.
This is an unresolved counterexample to unconditional graceful recovery. It is
an assertion failure, not an AddressSanitizer memory-error diagnosis.

All five intentionally faulty detector controls produced their expected
diagnostics: heap bounds, use after free, double free, invalid free, and signed
integer overflow. The valid control passed. These results establish sensitivity
of the instrumentation; arbitrary probe errors were detected by the sanitizers.
Valid aliased-root cleanup passed on all five profiles.

## Input mutation and model checks

Eight build/allocator combinations each executed the same 6,144 seeded malformed
inputs: 2,048 each for JSON, serialization, and session decoding. Every row also
performed 4,096 valid round-trip checks. All eight rows passed. The 49,152 malformed
input executions reuse the same corpus; they are not 49,152 distinct inputs.

The cleanup model explored 240 distinct states. Both negative controls produced
the intended invariant violation when a modeled protection was removed. The
model covers the declared finite state space and assumes valid ownership and
upstream safe-point completion.

## Evidence boundaries

The system allocator gives ASan visibility into individual allocations. Zend's
allocator run exercises real PHP memory limits but does not expose every internal
suballocation boundary to ASan. LeakSanitizer was disabled; MemorySanitizer and
ThreadSanitizer were not run. Leak freedom, uninitialized-read freedom, and race
freedom are therefore unestablished. The benchmark did not validate Windows,
thread-safe PHP, every extension, or production concurrency.

The runtime additions are operational and the measured compatibility result is
positive. Additional prevention of a demonstrated vulnerability beyond the
upstream repairs has not been established by these tests.

## Fresh package validation

The public identifiers were compiled in a fresh recoverable CLI/FPM build.
Native checks, the 384-case release allocation sweep, both release mutation rows,
the FPM scenarios, and all three model checks passed. The complete release suite
was also rerun: 14,836 passed, 8,804 skipped, 3 failed, 2 borked, 3 warned, and
7 expected failures.

The two additional failures were `006_variation2.phpt` and `chmod_basic.phpt`.
The temporary source directory inherited a filesystem group outside the test
user's memberships, so attempts to set the group-execution bit were cleared.
Both tests also failed with the existing upstream control in that directory.
After changing only the test directory's group to the user's primary group,
both tests passed with both binaries. The full-suite failure counts are retained;
the diagnostic reruns do not overwrite them. Details and normalized status files
are included with the package validation evidence.
