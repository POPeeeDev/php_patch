# Fresh public-clone reproduction

A new clone of the public GitHub repository built the PHP 8.2 candidate using
the published README and BUILD instructions. It reused the documented upstream
Git checkout option, but not earlier prepared sources or compiled objects.
This was a fresh project/build directory on the same test host, not provisioning
of a new operating system or independent third-party reproduction.

The build used commit `af025e56bf9477c4337bed0439da8631b1981578`. After a clean
fast-forward to `fac756e209e273e8f6d7be8d3afc4e3250585be6`, the corrected public
harness ran the documented smoke command against that unchanged build. The
patches and builder were unchanged between those commits.

- Package verification passed for the initial 290 files and the updated 293 files.
- CLI, CGI, and FPM compiled; the CLI reported PHP 8.2.35 NTS.
- All 12 smoke groups passed, including 384 sampled allocation cases, the native
  cleanup/retirement checks, deterministic mutations, and local FPM scenarios.
- No tracked checkout changes were present after the smoke test.

[Reproduction record](reproduction.json), [build identity](build.json), and
[smoke report](smoke/report.json) retain the details. Machine-specific paths use
logical placeholders. Passing per-allocation logs are omitted; the complete
case-result array remains. This is one fresh release-build reproduction, not a
second execution of the full 20-row matrix.
