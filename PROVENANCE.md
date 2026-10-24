# Source and evidence provenance

This document records the historical 8.7 package. Release manifests under
`releases/` identify the affected and official commits, complete upstream update,
guard hashes, and upstream licenses. The release preparation checks the complete
official Git tree before applying the guard; new run records retain build and
test identities separately. See [RELEASE_DESIGN.md](RELEASE_DESIGN.md).

## Source identity

- Upstream project: [php/php-src](https://github.com/php/php-src).
- Source revision: `022073187266c934fea9d5aafd9dde49cc09d449`.
- Runtime version reported by that snapshot: PHP 8.7.0-dev.
- Exported patch: `patches/oom-lifecycle.patch`.
- Original and resulting hashes for the five changed files:
  `patches/source-hashes.json`.

The public patch uses the `ZEND_OOM_GUARD_*` and `zend_oom_guard_*` names.
Its native changes preserve the tested implementation's operations and conditional
compilation branches. The packaging check compares the renamed source contents
against the recorded implementation before compilation.

## Recorded experiment

`evidence/2026-10-24/results.json` contains the completed experiment's test bounds,
outcomes, counts, service measurements, and original binary hashes. Those hashes
identify the binaries used for that experiment. They are not the hashes of newly
compiled public-package binaries.

The public evidence retains 43 relevant check groups: 35 passed and 8 reported
failures. The six full-suite groups remain failed because of their recorded
adverse results, and two allocation-sweep groups retain the shared assertion.
Expected negative-control failures count as successful detector checks only when
the required diagnostic or invariant violation was observed.

The evidence export removes machine-specific command paths and unrelated
integration checks. Source paths become `php-src/`, build paths become `build/`,
and test-module labels use the public `guard` prefix. Numbers, outcomes, source
line references, and diagnostic bodies are preserved. Logs are therefore
normalized evidence extracts, not byte-identical copies of the original files.
Request samples and per-allocation outcomes are included alongside summary data.

The complete upstream test inventory is retained as relative source paths in
`test-inventory.txt`. The failure directories retain `.out` and `.diff` extracts;
they are not a replacement for the upstream test definitions in the pinned source.

## Standalone harness

The supplied harness reads its fixed bounds from `bench/config.json` and resolves
source, build, and tool locations from environment variables. It preserves the
workload families, allocation sampling, service scenarios, and source-suite
discovery used in the recorded experiment.

One instrumentation difference is explicit: the public allocation-probe build
enables sanitizer flags for both sanitizer profiles. In the recorded sweep, the
control probe module lacked those flags while both PHP engine binaries were
instrumented. The historical numbers remain unchanged; a future run of this
package records its own results. This distinction does not affect the source
patch's identifier-only transformation.

## Package validation

`evidence/package-validation.json` records the checks actually performed on this
export, separately from the historical matrix. It identifies fresh build outputs,
model results, native checks, and any freshly executed benchmark rows. A full
historical sanitizer matrix is not implied by a fresh release-only validation.
The fresh full release run retained two additional permission-test failures.
Controlled reruns reproduced both with the upstream binary and resolved both for
each binary after correcting the test directory's group. The original fresh-run
counts and the follow-up outcomes are stored separately.

`SHA256SUMS` identifies all distributed package files other than itself. Check it
from the package root with:

```sh
php -n tools/check-package.php
```

Checksums detect changed contents relative to this manifest; they do not establish
publisher identity. Generated build products and new local measurements belong
under `work/`, which is excluded from the distributed manifest.
