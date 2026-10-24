# Release builds and reproduction

## Prerequisites

- A disposable POSIX test machine, ordinary-user account, disk space for isolated PHP
  source trees/builds, and a PHP 8.3-or-newer command-line interpreter.
- Git, C and C++ compilers, make, Autoconf, Bison, re2c, and pkg-config on PATH. The
  selected PHP extensions use PHP's bundled dependencies. Nothing is installed
  into the system by these scripts.
- Sanitized profiles require a compiler with AddressSanitizer and
  UndefinedBehaviorSanitizer. Both official and candidate probes are instrumented.

On macOS with Homebrew, its newer Bison may be installed but not on PATH. If so:

```sh
export PATH="$(brew --prefix bison)/bin:$PATH"
```

Confirm that `php -n -v`, `cc --version`, `bison --version`, `re2c --version`,
`autoconf --version`, and `make --version` run before starting. A missing PHP
interpreter is a missing prerequisite, not a patched-runtime result.

## Source checkout

From the package root:

```sh
git clone https://github.com/php/php-src.git work/php-upstream
export PHP_GIT="$PWD/work/php-upstream"
export RELEASE_JOBS=4
```

An existing upstream checkout containing the pinned tags/commits can be reused
by setting PHP_GIT to its absolute path instead of cloning again. The full clone
includes PHP history and can take a substantial download. Child checkouts borrow
its Git objects; keep it available and do not prune its objects during testing.

## Build an affected release with the patch series

```sh
php -n tools/release-build.php 8.2 candidate
work/releases/8.2/candidate/release-build/sapi/cli/php -n -v
php -n tools/release-test.php 8.2 candidate release smoke
```

Replace `8.2` with `8.3`, `8.4`, or `8.5`. The tool checks out the exact affected
release, applies `upstream-release.patch`, verifies the resulting official tree,
then applies `lifecycle-guard.patch` and verifies its three source hashes. It
builds CLI, CGI, and FPM. The `zend_test` extension is enabled for the benchmark,
not as a production recommendation. Acronyms are defined in [GLOSSARY.md](GLOSSARY.md).
Before compiling, tracked source timestamps are normalized without changing file
contents. This prevents patch ordering from unnecessarily regenerating vendor
VM files with the host PHP interpreter. A post-build check rejects tracked source
changes; the benchmark verifies executable hashes before testing.

To prepare and verify sources without compiling:

```sh
php -n tools/release-build.php 8.2 candidate release --prepare-only
```

For manual application, start from the exact affected tag listed in the manifest
and apply the two files in order with `git apply --check` followed by `git apply`
for each. Use Git, not a utility that ignores binary hunks. Do not use `--reject`
or skip failed hunks. The scripted workflow additionally checks the full official
tree and is the measured route.

## Full comparison

```sh
php -n tools/release-matrix.php 8.2 8.3 8.4 8.5
```

For each branch this builds and tests five rows:

| Profile | Variant | Contents |
|---|---|---|
| `unpatched` | `release` | Exact affected tag, without security/guard changes |
| `official` | `release` | Exact official fixed tag |
| `candidate` | `release` | Affected tag plus the two-patch series |
| `official` | `sanitized` | Debug official build with ASan and UBSan |
| `candidate` | `sanitized` | Debug candidate build with ASan and UBSan |

The matrix continues after failed test rows. Reports retain nonzero exits and
failed checks; overall exit 1 means at least one build/test row did not pass.
A completed matrix is not necessarily a clean test suite.

After an interruption, `php -n tools/release-resume.php 8.2 8.3 8.4 8.5`
retains completed attempts (including failures and timeouts), verifies their
executable hashes, and restarts unfinished rows in new report directories.
It does not reinterpret a previously timed-out suite as a pass.

Individual rows and phases:

```sh
php -n tools/release-build.php 8.2 official sanitized
php -n tools/release-test.php 8.2 official sanitized all
```

`smoke` runs native/service/mutation/allocation checks. `suite` runs full upstream
discovery for the configured extensions. `all` runs both. `regressions` runs every
existing PHPT test changed by the official security commit; it is a diagnostic
subset, not a substitute for `suite`.
After both full official and candidate rows finish, `followup` runs every
FAILED/BORKED/LEAKED status difference among tests observed in both rows, in either
direction, with one worker. Run it separately for both
profiles to investigate differences; its results never replace full-suite counts.
Tests missing from a timed-out row remain unobserved, not successful controls.

Each row uses an isolated working copy of the **same official-release corpus**
on its branch, including when testing the unpatched binary. New fixed-behavior
expectations can fail on the unpatched binary without demonstrating memory
corruption. No slow-test exclusion or passing-test-only selection is used. Tests
requiring absent extensions can skip; inspect counts and status files.
The tested runtime also drives the upstream runner, so version-dependent PHP
constants in the runner match the executable under test.

Suites have a 2,400-second wall-time bound; individual tests have a 20-second
timeout. Timeouts are inconclusive safety results, even when upstream labels them
FAIL. The supervisor bounds CPU time and captured output, disables core dumps,
and does not impose an address-space limit. It is not an OS sandbox. FPM runs on
a private Unix socket with one sequential worker, not a public listener.

## Outputs and controls

Reports, logs, status lists, and failure artifacts are under
`work/releases/<branch>/<profile>/`, in timestamped test directories. Build records
retain source identity, patch hashes, configuration, and executable hashes.
Completed builds are not overwritten by `release-build.php`.

Set `RELEASE_WORK_ROOT` to a new absolute directory for an independent rerun.
`RELEASE_JOBS` (1 through 32, default 4) controls compiler/test workers. Keep
aggregate jobs within machine capacity when running branches concurrently.
Do not run two tests in the same branch/profile/variant corpus at once.
The full upstream suites exercise host-global System V interprocess resources.
Some upstream tests leave objects behind; repeated suites can exhaust host limits
even when PHP exits. Use disposable test hosts or operating-system isolation,
not a production or shared service host. These scripts do not remove arbitrary
host IPC objects or raise kernel limits. Resource-exhaustion failures do not
establish a native memory-safety defect, and must remain in the evidence.

Sanitizer suites/probes use system allocation for better object coverage.
Soft-limit and FPM tests use Zend allocation so memory limits remain active;
ASan then cannot observe every Zend suballocation. LeakSanitizer is disabled.
Upstream `--asan` likewise selects `USE_ZEND_ALLOC=0`. Some OOM PHPT files do not
skip in that mode even though their expected output depends on PHP memory limits;
their failures/timeouts are not direct evidence of a guard regression. The suite
is retained without filtering those cases, and incomplete coverage is reported.
Unpatched releases lack the official allocation-counter API and do not run that
sweep; their common-corpus and service tests remain.

Historical development-snapshot instructions: [DEVELOPMENT_BUILD.md](DEVELOPMENT_BUILD.md).
