# Glossary

## Acronyms and tools

| Term | Definition in this package |
|---|---|
| ABI | Application Binary Interface: calling conventions and data layouts required for compiled components to work together. |
| API | Application Programming Interface: operations a component exposes to callers. |
| ASan | AddressSanitizer: compiler instrumentation that detects classes of invalid memory access. |
| CGI | Common Gateway Interface: a protocol between a web server and a program that produces responses. |
| CLI | Command-Line Interface: PHP's terminal executable. |
| CPU | Central Processing Unit: the processor executing the program. CPU time differs from elapsed wall time. |
| CVE | Common Vulnerabilities and Exposures: the identifier system for publicly disclosed security vulnerabilities. |
| DNS | Domain Name System: the name-resolution facility involved in the recorded alignment diagnostics. |
| FastCGI | A persistent-process protocol derived from CGI, used here to send requests to FPM. |
| FPM | FastCGI Process Manager: PHP's master/worker service that accepts FastCGI requests. |
| GC | Garbage Collection: reclaiming allocations no longer reachable by the application, including PHP reference cycles. |
| GHSA | GitHub Security Advisory identifier. |
| HTTP | Hypertext Transfer Protocol: the request/response protocol normally used by web clients. |
| INI | The configuration-file syntax used by PHP and FPM. |
| IPv6 | Internet Protocol version 6. The multicast failures involved this networking protocol. |
| JSON | JavaScript Object Notation: the text data format used for benchmark configuration and results. |
| JSONL | JSON Lines: one JSON record per line, used for request event logs. |
| MiB | Mebibyte: 1,048,576 bytes. |
| NTS | Non-Thread Safe: a PHP build without its thread-safe runtime configuration. Each tested FPM worker was a separate process. |
| OOM | Out Of Memory: an allocation or configured memory-budget failure. |
| PHP | PHP: Hypertext Preprocessor, the language and runtime modified here. |
| PHPT | PHP's upstream test-file format, with sections for setup, program input, and expected output. |
| PID | Process Identifier: the operating-system number used to distinguish workers in a measurement. |
| POSIX | Portable Operating System Interface: the family of system interfaces used for processes, signals, and resource limits here. |
| RCE | Remote Code Execution: execution of code through a remotely reachable vulnerability. |
| RAM | Random Access Memory: physical working memory. |
| RSS | Resident Set Size: memory resident in physical RAM; the supervisor reports the peak observed process usage. |
| SAPI | Server Application Programming Interface: the integration layer for a PHP execution environment, such as CLI or FPM. |
| SHA-256 | Secure Hash Algorithm with a 256-bit digest, used to identify exact file contents. A digest is not an authenticity signature. |
| TLA+ | Temporal Logic of Actions plus its specification language, used to describe the finite cleanup model. |
| TLC | The model checker distributed with TLA+ that explores the configured state space. |
| TSRM | Thread Safe Resource Manager: PHP's infrastructure for runtime resources in threaded builds. |
| UBSan | UndefinedBehaviorSanitizer: compiler instrumentation that detects selected violations of C language rules. |
| VM | Virtual Machine: PHP's execution engine for compiled PHP instructions. |
| ZTS | Zend Thread Safety: PHP's threaded runtime configuration; the guard rejects this build mode. |

## Runtime and verification terminology

| Term | Meaning |
|---|---|
| Allocator | Code that obtains and releases memory for the runtime. |
| Alias | Another reference to the same allocation. Two owning references require two valid ownership counts. |
| Alignment | An address requirement for a C type. Access through a misaligned pointer can violate the language's rules. |
| Assertion | A programmed condition checked by a debug build. A failed assertion terminates the process. |
| Backtrace | A record of the call sequence leading to an error. |
| Backport | Adapting a newer change to an older source branch. This package pins four release inputs and separately retains one historical development experiment. |
| Baseline/control | The reference configuration used to attribute differences to the candidate. Here the upstream control already contains vendor fixes. |
| Binary | A compiled executable or library. |
| BORK/Borked | An upstream test whose definition or setup could not be interpreted correctly. It is not a passed runtime test. |
| Callback | A function invoked by another operation, including user code invoked during a native operation. |
| Checkpoint | A specific point where selected conditions are inspected before processing continues. |
| Checksum | A digest used to compare file contents with a recorded value. |
| Compiler | A tool that translates source code into executable instructions. A compile-time option is chosen during that translation. |
| Counterexample | A concrete execution or modeled state that violates the proposed property. |
| Debug build | A runtime compiled with extra internal checks and diagnostic information. |
| Detach before release | Remove globally visible references first, then release the locally held ownership references. |
| Double free | Releasing the same allocation more times than its ownership permits. |
| Expected failure/XFAIL | A test marked by its maintainers as currently expected to fail. |
| Exit status | A numeric result returned by a process when it ends. Signal termination is recorded separately. |
| Extension | A PHP component providing additional functions or runtime integration. |
| Fairness assumption | In the model, a continuously enabled progress action must eventually occur rather than being postponed forever. |
| Fatal error | An error that stops a PHP request; it does not always terminate the worker process. |
| Fault injection | Deliberately forcing a failure at a specified point to test handling. |
| Fiber | A PHP execution context that can suspend and resume cooperatively. |
| Hard OOM | A failure that requires immediate process termination in the repaired path, such as exceeding the higher hard limit. |
| Heap | Memory managed dynamically during execution. The request heap holds allocations owned by one PHP request. |
| Instrumentation | Extra checks inserted by a compiler or test tool to observe execution. |
| Leak | An allocation that is no longer needed but remains unreclaimed. LEAK is also an upstream test-result category. |
| LeakSanitizer | A detector for unreclaimed allocations; disabled in these measurements. |
| Macro | A C preprocessor definition expanded before compilation, such as the global-access helpers in the patch. |
| Invalid free | Attempting to release an address that is not a valid releasable allocation. |
| Invariant | A condition required to remain true across the states or transitions being checked. |
| Memory amplification | Runtime memory use much greater than the input's byte size. |
| Metamorphic/round-trip check | Checking a relation between operations, such as decoding an encoded value and comparing it with the original. |
| Multipart | A request-body format containing multiple fields or file parts, commonly used for uploads. |
| Mutation | A deterministic change to an input used to exercise parser or decoder behavior. |
| MemorySanitizer | A detector for selected uses of uninitialized memory; not run in this experiment. |
| Multicast | Network delivery to a group of recipients rather than a single destination. |
| Native | Code compiled into machine instructions, principally C code in this patch. |
| Negative control | A deliberately faulty case used to verify that a detector or model can identify the intended error. |
| Ordinal of an allocation | Its counted position in a sequence of allocation attempts. Here this is an integer test index. |
| Ownership/reference count | The runtime's accounting for references responsible for keeping an allocation alive. |
| Percentile | A position in sorted measurements; p50 is the median, and p95 has approximately 95% of samples at or below it. |
| Published reference/root | A pointer or value retained in state accessible to other runtime operations. The checkpoint inspects selected error roots. |
| Raw allocation failure | Failure to obtain memory from the underlying allocation path, distinct from a configured soft-limit crossing. |
| Refinement | Showing that an implementation satisfies the behavior represented by a model. Full C-to-model refinement is not supplied. |
| Request boundary | The transition between completing one request and beginning another in the same worker. |
| Release build | A normal optimized build, contrasted with the extra checks enabled by a debug build. |
| Retirement | Ending a worker process so that it cannot execute later consumers or requests. |
| Runtime | The engine and libraries that execute a program and manage its state. |
| Safe point | A runtime location at which delayed error handling can occur while the interrupted operation's required state is coherent. |
| Serialization | Converting values to a stored or transmitted representation; decoding reconstructs values from it. |
| Session | Application state maintained across requests, commonly encoded and decoded by PHP's session extension. |
| Set-group-ID bit | A filesystem permission bit associated with group execution or inheritance. The operating system may clear it when the caller does not belong to the file's group. |
| Shutdown callback | Application code registered to run during ordinary request shutdown. Immediate process exit skips it. |
| Soft OOM | Crossing PHP's normal memory limit and deferring the request failure to a VM safe point in the repaired runtime. |
| Socket | An endpoint for communication. A Unix socket connects local processes without a public network listener. |
| Stale reference/state | State that still refers to data whose lifetime has ended or whose validity has been interrupted. |
| Suballocation | An allocation carved from a larger allocator-managed block; its individual boundaries may be invisible to ASan. |
| ThreadSanitizer | A detector for data races between threads; not run in this experiment. |
| Undefined behavior | C execution for which the language does not prescribe a valid result. |
| Use after free | Accessing memory after the allocation's lifetime has ended. |
| Wall time | Actual elapsed time, including waiting and operating-system scheduling. |
| Worker churn | Repeated worker termination and replacement. |
| Zend | The name of PHP's core execution engine, not an acronym. |

## Configuration and C names

| Name | Meaning |
|---|---|
| `ZEND_OOM_GUARD_MODE` | Historical 8.7 experiment selector: 0 upstream, 1 strict retirement, 2 recoverable. The release patches do not use this mode selector. |
| `memory_limit` | PHP's configured memory allowance for a request; `-1` disables that limit. |
| `memory_hard_limit_percent` | The pinned runtime's higher-limit control used by its hard-OOM handling. |
| `pm.max_requests` | Maximum requests handled by an FPM worker before replacement; 0 disables this count-based recycling. |
| `PG(...)` | PHP's macro for accessing a PHP core global slot. |
| `EG(...)` | PHP's macro for accessing a Zend executor global slot. |
| `CG(...)` | PHP's macro for accessing a Zend compiler global slot. |
| `NULL` | The C null-pointer value, used here to mark an empty reference slot. |
| `Z_ISUNDEF(...)` | A check that a Zend value is in its undefined/unset state. |
| `zend_string_release(...)` | Releases one ownership reference to a Zend string. |
| `_exit(137)` | Immediate process termination with exit status 137; this call is distinct from being killed by signal 9 even though shell conventions can produce the same numeric status. |
| `longjmp()` | A C operation that transfers control to a saved context without ordinary return through intervening calls. |
| `cgroup` | A Linux control group that can apply resource bounds to a set of processes. |
| `RLIMIT_AS` | A process address-space limit; reaching it can make underlying allocations fail. |

## Release workflow terms

| Term | Meaning |
|---|---|
| Release delta | All file differences between two exact upstream release commits, including maintenance changes unrelated to these CVEs. |
| Git tree | Git's content identity for an entire versioned directory hierarchy, including paths and file modes. Equality is checked before adding the guard. |
| Patch series | Patches applied in a specified order: the complete official release delta, then the additional guard. |
| Corpus | The collection of test files. Each branch uses one fixed official-release corpus for all profiles. |
| PHPT | PHP's test-file format, including setup, execution, expected output, and optional skip conditions. |
| CGI | Common Gateway Interface, a protocol and executable interface for a web server to invoke an application. |
| Unpatched profile | The exact affected release without the upstream update or guard. |
| Official profile | The exact upstream fixed maintenance release without the additional guard. |
| Candidate profile | The affected source with the full official update and additional guard applied; reports the fixed PHP version. |
| Sanitized variant | Debug build instrumented with ASan and UBSan; not sanitization of untrusted input. |
| Build receipt | A record of source identity, patch hashes, build configuration, and executable hashes. It is evidence, not a formal proof. |
| IPC | Interprocess communication. System V IPC includes kernel-managed message queues, semaphores, and shared-memory segments that can outlive a PHP process. |
| BORKED | An upstream test could not be set up or interpreted correctly; this is not a successful test. |
| Paired observation | The same test has a recorded outcome for both the official and candidate binary. |
| Inconclusive | The test did not establish the required result, for example because of a timeout or supervisor setup failure. |
| Symbolization | Converting raw instruction addresses into function names and source locations in a diagnostic. Disabling it does not disable the sanitizer's memory-error checks. |
| Tracked malloc | PHP's wrapper around system allocation that records ownership sizes and checks memory limits; selected by the pinned upstream ASan runners. |
| Process group | An operating-system grouping used by the supervisor to terminate a command and its descendants without intentionally targeting unrelated jobs. It is not an isolation sandbox. |
| `PHP_GIT` | Local upstream Git checkout supplying the pinned commits for isolated build sources. |
| `RELEASE_WORK_ROOT` | Absolute directory for generated release sources, builds, and evidence. |
| `RELEASE_JOBS` | Number of parallel compiler or upstream test workers per active row. |
| `zend_oom_guard_check_request_state()` | Release-guard checkpoint checking selected global slots before the request heap is reclaimed. |

