# Building the historical PHP 8.7 experiment

For affected-release inputs and the release comparison, use [BUILD.md](BUILD.md).

## Requirements

- A POSIX development system. The recorded runs used macOS x86_64. Linux command
  paths are supported by the harness but have not been validated in this package.
- A C compiler, `make`, `patch`, and the build dependencies required by the pinned
  PHP source. A Git checkout normally needs Autoconf, Bison, re2c, and pkg-config.
- An existing PHP command-line interpreter, version 8.3 or later, to run the build
  and measurement scripts. The recorded controller was PHP 8.7.0-dev.
- For sanitizer profiles, a compiler supporting AddressSanitizer and
  UndefinedBehaviorSanitizer.
- For the optional model checks, Java and the official
  [TLC distribution](https://github.com/tlaplus/tlaplus/releases).

The compiler and build utilities are taken from `PATH`. `CC` and `MAKE` may name
alternative executables. No system PHP installation is replaced.

## Prepare an isolated source checkout

Run from this package's root. If a clean checkout of the exact revision is already
available, set `PHP_SOURCE` to that directory and skip the clone and checkout.

```sh
export PACKAGE_ROOT="$PWD"
git clone https://github.com/php/php-src.git work/php-src
export PHP_SOURCE="$PACKAGE_ROOT/work/php-src"
git -C "$PHP_SOURCE" checkout --detach 022073187266c934fea9d5aafd9dde49cc09d449
cd "$PHP_SOURCE"
./buildconf --force
cd "$PACKAGE_ROOT"
php -n tools/prepare.php
```

`prepare.php` checks the original hashes of all five changed files, performs a
dry run, applies the patch, and verifies the resulting hashes. It rejects mixed
or unexpected source contents. It recognizes an already applied matching patch.
These file checks do not verify every other file in the source checkout.

## Build and exercise the candidate

```sh
php -n bench/build.php recoverable
work/build/recoverable/sapi/cli/php -n -r 'echo PHP_VERSION, PHP_EOL;'
php -n bench/verify.php native
GUARD_SERVICE_PROFILES=recoverable:0 php -n bench/verify.php service
GUARD_FAULT_PROFILES=recoverable php -n bench/verify.php faults
GUARD_MUTATION_PROFILES=recoverable php -n bench/verify.php mutations
```

The build creates both the command-line interpreter and FPM. The native tests
check graceful soft OOM and termination on raw allocation failure or a dirty
checkpoint. The service test launches FPM on a private Unix socket, sends healthy
and failing requests, measures worker replacement, then stops that FPM instance.
The allocation sweep injects failure inside the tested process; it does not fill
host memory. Probe extensions are test fixtures and are not application plugins.

Run an application with the candidate interpreter by passing its PHP script as
the final argument. The `-n` option disables the system configuration for the
benchmark; an actual application may require configuration and extensions that
the minimal benchmark build does not provide.

## Build the complete comparison matrix

```sh
php -n bench/build.php
```

| Profile | Guard mode | Instrumentation |
|---|---:|---|
| `upstream` | 0 | Release control |
| `strict` | 1 | Release control that retires at selected OOM boundaries |
| `recoverable` | 2 | Release candidate |
| `sanitized-upstream` | 0 | Debug control with ASan and UBSan |
| `sanitized` | 2 | Debug candidate with ASan and UBSan |

Set `TLA_JAR` to the downloaded `tla2tools.jar`, or place it at
`tools/tla2tools.jar`. Then run:

```sh
php -n bench/verify.php all
```

The complete run includes the model, native checks, detector controls, allocation
sweeps, service measurements, input mutations, and six upstream test-suite rows.
The recorded full matrix took approximately 87 minutes including auxiliary checks.
Each full-suite row has a 2,400-second bound; machine speed can change completion.

Each phase can also run separately: `model`, `native`, `sanitizers`, `faults`,
`service`, `mutations`, or `suite`. The `suite` phase uses upstream full discovery
for the built extensions. It does not select only previously passing tests.
Run suite rows sequentially against a given source tree because the upstream
runner creates neighboring temporary files.

## Paths and selection controls

| Variable | Meaning |
|---|---|
| `PHP_SOURCE` | Required prepared source directory |
| `PHP_BUILD_ROOT` | Absolute build root; defaults to `work/build` under this package |
| `PHP_CONTROLLER` | Optional executable for child harness scripts; defaults to the running PHP |
| `GUARD_SAFETY_OUT` | Optional shared output directory for staged phases |
| `TLA_JAR` | TLC Java archive |
| `GUARD_SUITES` | Comma-separated rows such as `upstream:zend,recoverable:zend` |
| `GUARD_SERVICE_PROFILES` | Comma-separated pairs such as `recoverable:0,upstream:1` |
| `GUARD_FAULT_PROFILES` | Comma-separated build profiles for allocation sweeps |
| `GUARD_MUTATION_PROFILES` | Comma-separated build profiles for input mutation tests |

Selection variables are intended for staged execution. A selected subset is not
a complete matrix. Leave them unset when running the full comparison.

Fresh outputs default to `work/evidence/`. Each report includes commands, hashes,
timing, failures, and scope. Exit status 0 means the executed checks met their
expectations; 1 records failures; 2 records inconclusive checks when no failure
also occurred. Upstream per-test timeouts appear as FAIL and must be interpreted
as inconclusive safety results. Missing tools can terminate the harness before
a report is complete. Read the recorded checks, not only the process exit code.

`bench/config.json` fixes the documented bounds. The process supervisor limits
wall time, CPU time, and captured output, and disables core dumps. It does not
impose an address-space limit that would alter OOM behavior. It is not an operating
system sandbox. The FPM benchmark is intended to run as an ordinary user.
