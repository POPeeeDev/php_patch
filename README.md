# PHP OOM Lifecycle Guard

Version-pinned experimental patches and a reproducible comparison of affected
PHP releases, official maintenance releases, and an additional native cleanup
guard. OOM means **out of memory**.

## Release targets

| Affected input | Official comparison | Candidate |
|---|---|---|
| 8.2.34 | 8.2.35 | 8.2.34 + complete official update + guard |
| 8.3.35 | 8.3.36 | 8.3.35 + complete official update + guard |
| 8.4.26 | 8.4.27 | 8.4.26 + complete official update + guard |
| 8.5.11 | 8.5.12 | 8.5.11 + complete official update + guard |

Each directory under `releases/` contains two patches, a manifest with source and
patch hashes, and the applicable upstream licenses. The first patch is the
**complete official maintenance-release delta**, including unrelated maintenance
changes. The second is the separately attributable, three-file lifecycle guard.
Preparation verifies the exact official Git tree before applying the guard.
Candidate executables report the fixed PHP version, not their affected input.

This is not an independent replacement for the official security repair. The
guard preserves its delayed soft-OOM handling, broader state resets, and graceful
shutdown. It additionally detaches two error references before release, checks
selected roots before request-heap reclamation, and uses `_exit(137)` on raw
native allocation failure or a failed checkpoint. See [RELEASE_DESIGN.md](RELEASE_DESIGN.md).

Only the four exact affected input releases above are supported by this workflow.
Do not apply these patch files to other revisions by ignoring failed checks.
The experimental guard requires POSIX, non-thread-safe PHP; it is not an official
PHP release or a recommendation to replace vendor-supported production packages.

## Build and test

Requires PHP 8.3 or newer and the development prerequisites in [BUILD.md](BUILD.md).

```sh
git clone https://github.com/POPeeeDev/php_patch.git
cd php_patch
php -n tools/check-package.php
```

Follow [BUILD.md](BUILD.md) for prerequisites, preparation, candidate builds, and
the full comparison. No system PHP is overwritten. Read [FINDINGS.md](FINDINGS.md)
for actual outcomes, including failures, skips, and the distinction between a
completed test run and a passing one.

## Evidence and scope

The release workflow uses the same official-release test corpus for all three
profiles on each branch. It also exercises native cleanup, local service recovery,
deterministic input mutations, and allocation-failure sweeps where upstream
instrumentation exists. Debug builds pair AddressSanitizer and
UndefinedBehaviorSanitizer with the official and candidate profiles.

Passing tests do not prove general memory safety or additional prevention of a
demonstrated remote-code-execution vulnerability. Missing extensions and
unsupported platforms remain outside the exercised scope. Definitions are in
[GLOSSARY.md](GLOSSARY.md); published mitigation guidance is compared in
[MITIGATIONS.md](MITIGATIONS.md).

The [release evidence](evidence/releases-2026-10-24/README.md) records all 20
build/test attempts, including failed normal suites and time-bounded partial
instrumented suites. A [fresh public-clone build and smoke test](evidence/public-clone-2026-10-24/README.md)
is recorded separately. These are experimental findings, not a production-ready
or all-tests-passing certification.

The original 8.7 development experiment, its bounded TLA+ model, and recorded
results remain in [DEVELOPMENT_README.md](DEVELOPMENT_README.md). They are not
substituted for release-branch results.
