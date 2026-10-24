# Historical PHP 8.7 development experiment

This records the development-snapshot experiment. Release-targeted instructions
and current results are described in [README.md](README.md).

A working experimental PHP runtime patch that adds selected cleanup checks while
preserving the upstream handling of an out-of-memory (OOM) request.

The patch is written in C and compiled into PHP. Applications continue to run
ordinary PHP code. The package includes a standalone benchmark, recorded results,
and a small model of the cleanup sequence.

## Get started

```sh
git clone https://github.com/POPeeeDev/php_patch.git
cd php_patch
php -n tools/check-package.php
```

Follow [DEVELOPMENT_BUILD.md](DEVELOPMENT_BUILD.md) for prerequisites, the pinned PHP source checkout,
build profiles, and individual or full test runs. Recorded evidence is included;
compiled PHP binaries and downloaded dependencies are not.

## What the candidate does

- Clears two published error references before releasing either referenced string.
- Checks selected error state before PHP reclaims the request heap.
- Terminates the worker if that checkpoint fails or a raw native allocation fails.
- Preserves upstream garbage collection, delayed soft OOM, normal request shutdown,
  and hard-limit diagnostics in the recoverable configuration.

The implementation targets PHP **8.7.0-dev**, source revision
`022073187266c934fea9d5aafd9dde49cc09d449`. This snapshot already contains the
upstream OOM and request-state repairs. The supplied patch adds a compile-time
option to that snapshot; it is not a backport to older releases.

## Measured behavior

Each scenario used one PHP FastCGI Process Manager (FPM) worker, 81 sequential
requests, and, for the soft-OOM scenario, 10 deliberately failing requests.

| Configuration | Worker changes during healthy requests | Worker changes during soft OOM | Unaffected requests lost |
|---|---:|---:|---:|
| Upstream control | 0 | 0 | 0 |
| One request per worker | 80 | 80 | 0 |
| Strict retirement control | 0 | 10 | 0 |
| Recoverable candidate | 0 | 0 | 0 |

The candidate preserved upstream recovery and reduced worker replacement relative
to strict retirement and one-request recycling. The measurements do not establish
a throughput advantage over upstream or additional prevention of a demonstrated
vulnerability. Shared native errors and test failures remain in the evidence.

## Package contents

| Item | Purpose |
|---|---|
| [DEVELOPMENT_BUILD.md](DEVELOPMENT_BUILD.md) | Prepare the source, build the runtime, and run tests |
| [DESIGN.md](DESIGN.md) | Native operations, modes, assumptions, and model |
| [DEVELOPMENT_FINDINGS.md](DEVELOPMENT_FINDINGS.md) | Development comparisons, failures, and interpretation |
| [MITIGATIONS.md](MITIGATIONS.md) | Comparison with the published PHP guidance |
| [GLOSSARY.md](GLOSSARY.md) | Acronyms, configuration names, and technical terms |
| [patches/oom-lifecycle.patch](patches/oom-lifecycle.patch) | Complete source patch |
| `bench/` | Portable configuration, build tools, workload tests, and native probes |
| `models/RequestCleanup.tla` | Bounded cleanup model and its negative controls |
| `evidence/2026-10-24/` | Recorded measurements and normalized failure outputs |
| [PROVENANCE.md](PROVENANCE.md) | Source identity, evidence transformations, and package checks |

The measured platform is macOS x86_64 with non-threaded PHP. The guard rejects
thread-safe and Windows builds at compilation. General extension compatibility,
production concurrency, and complete native memory safety remain outside the
verified scope. See the specific limitations in DEVELOPMENT_FINDINGS.md.

The upstream PHP license is retained in [UPSTREAM-LICENSE](UPSTREAM-LICENSE).
This package is an independent experimental modification, not an official PHP release.
