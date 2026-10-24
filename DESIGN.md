# Native implementation

## Compile-time modes

`ZEND_OOM_GUARD_MODE` selects the implementation. It defaults to 0.

| Mode | Soft limit and selected pending-OOM consumers | Raw allocation failure | Cleanup |
|---|---|---|---|
| 0 | Upstream behavior | Upstream behavior | Upstream behavior |
| 1 | Immediate worker retirement | Immediate worker retirement | Detach before release and selected checkpoint |
| 2 | Upstream delayed soft-OOM path | Immediate worker retirement | Detach before release and selected checkpoint |

Mode 2 is the recoverable candidate. Mode 1 is a comparison control. Nonzero modes
are restricted to POSIX non-threaded builds. The runtime does not invoke a policy
interpreter or proof checker for application requests.

## Detach before release

In `main/main.c`, `clear_last_error()` takes local copies of the last-error message
and filename, clears both globals, then releases the locally held references:

```c
zend_string *message = PG(last_error_message);
zend_string *file = PG(last_error_file);
PG(last_error_message) = NULL;
PG(last_error_file) = NULL;
if (message) zend_string_release(message);
if (file) zend_string_release(file);
```

The two slots must own valid references. If they point to the same string, its
reference count must include both owners. Clearing both globals first prevents
these globals from publishing a reference that cleanup has already released.
This ordering does not validate arbitrary pointers or repair a corrupted reference
count. The benchmark exercises two valid, independently owned aliases.

## Final checkpoint

After ordinary error cleanup and before request-heap reclamation, the patch checks:

```c
if (PG(last_error_message) != NULL ||
    PG(last_error_file) != NULL ||
    !Z_ISUNDEF(EG(last_fatal_error_backtrace))) {
    zend_oom_guard_retire();
}
```

The checkpoint recognizes a nonempty selected slot; it does not dereference the
referenced allocation. It covers these three error roots. Other extension globals
and other request-owned state remain the responsibility of the upstream reset
logic and their owners. A test-only post-deactivation hook deliberately leaves
the backtrace slot dirty and confirms termination at this checkpoint.

## Worker retirement

`zend_oom_guard_retire()` calls `_exit(137)` and does not return. The leaf itself
does not allocate, release references, run callbacks, or format a log message.
The operating system reclaims the process resources. This is termination, not
graceful application cleanup or transaction rollback.

Mode 2 calls this leaf for raw allocation failure and a failed selected checkpoint.
Finite hard-limit OOM retains the upstream diagnostic and terminal `_exit()` path.
The whole hard-limit path is therefore not described as a silent, allocation-free
leaf. The distinction preserves upstream diagnostic expectations.

## Soft OOM and native consumers

The upstream snapshot already delays soft OOM until a virtual-machine safe point.
Mode 2 retains that behavior, including garbage collection and ordinary shutdown.
The extra pending-OOM consumer barriers are active only in mode 1. They occur at
selected error, delayed-OOM, shutdown, session-decoding, and upload-progress paths.
They intentionally perform no pending-OOM retirement in mode 2.

The candidate depends on the upstream safe-point and state-reset implementation.
It is not an independent substitute for that implementation.

## Bounded cleanup model

`models/RequestCleanup.tla` represents two selected roots, two requests, and a
20-transition budget. It checks detach-before-release, absence of a modeled stale
consumer, clean completed reuse, and termination under the model's fairness
assumption. The pair `(remaining fuel, held-reference count)` decreases on modeled
progress. The completed run explored 240 distinct states.

Removing detach-first ordering or the final checkpoint produces the intended
invariant violation. These negative controls test whether the model detects those
specific mistakes. The safe-point transition and valid ownership are assumptions;
the model does not derive them from PHP's C source. Its finite transition budget
is separate from elapsed-time limits enforced by the benchmark supervisor.

## Assumptions and uncovered state

The implementation assumes valid C execution, valid reference ownership, upstream
state reset, correct compiler output, and operating-system process termination.
The benchmark found shared DNS alignment diagnostics and a callback assertion;
the candidate did not prevent those failures. Native memory coverage, unsupported
extensions, and concurrent workloads are described in FINDINGS.md.
