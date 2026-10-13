# Development

All PHP, Composer and PHPUnit commands run through Docker/Make. Follow [AGENTS.md](../AGENTS.md) for code, review and contribution rules.

## Setup and quality checks

Prerequisites: Docker.

Install dependencies:

```bash
make install
```

Run the mandatory quality gate:

```bash
make test
```

`make test` includes:

- Linting (`phplint`)
- Coding standards dry-run (`php-cs-fixer --dry-run`)
- Refactoring dry-run (`rector --dry-run`)
- Static analysis (`phpstan`)
- Architecture layers (`deptrac`, plus the unassigned-class and layer-cycle checks)
- Template lockstep (`check-consumer-config.php`)
- Unit and integration tests (`phpunit`)
- Copy/paste detection (`jscpd`)

`make runtime-image-check` also runs `scripts/check-configuration-contract.sh`: tiny generated JPEG/HEIC fixtures prove Docker policy forwarding, actual canonical/merge decisions, invalid-value rejection, CLI precedence, defaults and external host paths with spaces. It uses disposable caches and preserves every synthetic media name and byte.

Application dependencies use the versioned `composer.lock`: normal installation, CI, the runtime image, binary packaging and the isolated no-dev check use that exact input. Missing lockfiles fail before installation rather than triggering fresh resolution. Use `make update` for an intentional dependency change, review the lock diff and run the full pipeline plus runtime checks before committing it.

SPC bootstrap uses the reviewed release and archive/binary SHA-256 values in `config/build-inputs.env`. It checks downloads and cached executables before use, selects the fixed PHP source URL/hash, and records native archive hashes and Git/submodule commits. The pinned builder image contains the compiler prerequisites and the official Composer PHAR; no extra Composer installer or packed Composer executable is needed. Download and compile use separate containers, and compile/package containers receive no download credentials. Cached compiler outputs require matching input/output receipts; `make binary-clean` clears only generated bootstrap/tool outputs so changed inputs can be rebuilt.

`make binary` runs all PHP/Composer/SPC build steps inside the builder container. Runtime images contain `.build/build-inputs.json`; binary builds contain the same manifest inside the PHAR and write `renamer.build.json` alongside the binary. It records source/lock hashes, actual package references, tool/native build versions and base-image digests. Make passes the checked-out Git revision and an explicit modified-workspace flag. Manual Docker builds without a revision use an explicit workspace identity instead of inventing a commit. No environment dumps, checkout paths or media metadata belong in this public artifact.

These records support rebuilding and diagnosing the selected inputs; they do not promise bit-for-bit identity across compilers, system repositories and archive generation. Review manifests and run no-dev/runtime/artifact checks when updating dependencies or build inputs.

The production dependency tree can be checked separately with `make no-dev-smoke`. It installs the application into a temporary isolated `--no-dev` vendor tree, starts the CLI, exercises the runtime process and video fingerprinting paths, and verifies the Write-Date and missing-`exiftool` diagnostics without inheriting development packages.

The tooling configuration is shared with the other `magicsunday/*` projects through
[`magicsunday/coding-standard`](https://github.com/magicsunday/coding-standard), the only
quality-tool entry in `require-dev` besides Infection: it delivers php-cs-fixer, PHPStan and
its rule packs, Rector, phplint, PHPUnit and Deptrac. `.php-cs-fixer.dist.php`, `phpstan.neon`
and `rector.php` only wrap the shared configs (`php-cs-fixer/base.php`, `phpstan/base.neon`,
`rector/base.php`), and `deptrac.yaml` imports the shared layer ruleset
(`deptrac/layers.yaml`). `phpunit.xml`, `.phplint.yml`, `.editorconfig` and `.jscpd.json` are
adapted copies of the package's templates; `composer ci:test:php:templates` keeps them from
drifting.

### Architecture layers

`deptrac.yaml` maps `src/` onto layers that may only depend downwards (lowest first):

```
Exception, Constants < Regex < Model < Helper < Contract < Metadata < Service < Strategy < Command
```

`Model` (all of `src/Model` plus the `TemporalMetadata` value object) and `Service` (all of
`src/Service`) are the shared layers of the same name; `Contract` is the shared port layer and
holds the strategy interfaces the services program against. `Command` is the composition root
(the console application and its commands); no layer depends on it. The file itself documents
why each edge is allowed.

PHPStan and Deptrac check types and dependency direction. PHPUnit tests protect
observable CLI behavior, file state, analysis evidence and actual container wiring.
Internal class suffixes, import lists, inheritance and constructor layouts are
implementation choices; behavioral tests do not require a particular decomposition.
Keep distinct source/target/deletion, classification-failure, cleanup and repeated-run
cases even when internal services or models are simplified.
Broad CLI/media integration tests use `CoversNothing` without reducing their
behavioral assertions. Focused rule and cache tests retain class-level coverage
metadata; coverage attribution is separate from whether a behavior is checked.

Test the CLI:

```bash
MEDIA_DIR="$HOME/Photos" ./renamer.sh rename:exif --dry-run --list-all /media
```

`rename:exif` uses capture grouping, content classification, role assignment, naming, plan validation and guarded execution. The simple hash/pattern/date/lowercase commands use the bounded legacy execution path. Both callers use the shared content kernel and target resolvers. Source/lock/journal/deletion checks remain execution infrastructure, independent of classification evidence.

`AbstractMediaRenameCommand` owns shared CLI behavior. `AssetGroupPipeline::run()` owns explicit analysis configuration and `finally` cleanup. Metadata timestamps and runtime plan values are immutable; the execution builder decides which quality-policy entries may mutate.

Still-image Stage B uses `LocalDifferenceAnalyzer::analyzeRmse()`; the retained `analyze()` entry point returns the same measurements. `LocalDiffResult` contains grayscale RMSE, mean chroma difference and explicit availability. Thresholds belong to `ContentClassifier`, which performs Stage B even at zero dHash distance and keeps unavailable evidence separate from a measured match. These aggregate metrics do not locate every local edit or prove byte identity; permanent deletion uses its independent fresh SHA-256 guard.

`OutputSkipReasonDecider` maps exclusive tags to semantic reasons, `SkipReasonFormatter` owns plain text, and the presenter owns Console escaping and styling. Link configuration and dedup format ranks are explicit per-run values.


### Individual CI targets

| Target         | Description                          |
|----------------|--------------------------------------|
| `make lint`    | Run PHP linter only.                 |
| `make cgl-check` | Check code style (dry-run).       |
| `make rector-check` | Check Rector rules (dry-run). |
| `make stan`    | Run PHPStan analysis.                |
| `make deptrac` | Check the architecture layers (Deptrac, unassigned classes, layer cycles). |
| `make templates` | Check the config copies against the coding-standard templates. |
| `make unit`    | Run PHPUnit tests.                   |
| `make coverage` | Run PHPUnit with HTML + Clover coverage report (`.build/coverage/`). |
| `make mutation` | Run Infection with the configured mutation-score gates. |
| `make cpd`     | Run copy-paste detection.            |
| `make no-dev-smoke` | Verify the isolated production vendor tree and runtime dependencies. |
| `make runtime-image-check` | Build the runtime image and verify its native media decoder contract. |

Quality targets propagate tool failures, including risky coverage tests and failed
mutation-score gates, as a nonzero Make status. Infection `^0.35.2` supports the
PHPUnit 13 initial-run configuration without weakening the repository's strict
PHPUnit configuration. Coverage runs retain the strict
PHPUnit metadata and warning/risky guards. PCOV/Clover reports statement coverage;
it does not prove branch coverage, safe deletion, successful file moves or Live
Photo integrity. Use the real execution, negative safety and repeated-run tests
alongside coverage reports. Mutation results measure the exercised mutations,
not a guarantee against every defect; CI integration is maintained separately.

`make test` is the fast lint/style/static-analysis/architecture/unit/integration/CPD
aggregate. Composer audit and full mutation testing remain separate local targets.
The PR `build (8.5)` job also audits the complete locked production/development
graph; audit failure fails that required build context, even if CPD succeeds.
The Security workflow audits the lock on PRs, pushes, its weekly schedule and manual
runs. Its full PCOV/Infection job runs weekly or through **Run workflow**, retaining
the configured 68% covered-MSI gate and propagating failures.

The full mutation lane has a 45-minute job timeout and 4 GiB RAM without additional
swap, 4 CPU and 256-PID container limits. Full mutation is a scheduled/manual report,
not a required PR merge context. Other Security scanner contexts also follow the
repository's separate branch-protection policy; their existence does not imply
that they are required. The configured mutation-score guard remains mandatory for that lane.

### Test images

Generate synthetic test files covering all renamer scenarios (duplicates, Live Photos, timezone, drift, HEIC, cross-directory, perceptual hashing, etc.):

```bash
docker compose run --rm buildbox php scripts/create-test-images.php
MEDIA_DIR="$PWD/tests/Fixtures/Images" MEDIA_READ_ONLY=true ./renamer.sh rename:exif --dry-run --list-all /media
```

### Fix targets

| Target         | Description                          |
|----------------|--------------------------------------|
| `make cgl`     | Auto-fix code style.                 |
| `make rector`  | Apply Rector rules.                  |

### Build a standalone binary (optional)

Compiles a Linux standalone executable with PHP, production packages and Imagick/ImageMagick through [static-php-cli](https://github.com/crazywhalecc/static-php-cli). Install `ffmpeg`, `ffprobe` and `exiftool` on the execution host for video and metadata-writing capabilities. Build in Docker and apply the [standalone isolation requirements](runtime-and-safety.md#capability-prerequisites-and-failure-behavior) when running it.

`make binary-init` preserves an existing `.env` byte for byte, including comments and runtime settings. Its builder invocation uses the calling user's UID/GID through transient environment overrides. If `.env` is missing, initialization copies all `.env.dist` defaults and substitutes those initial numeric IDs. Docker/authentication failure does not erase existing configuration.

```bash
make binary         # Init SPC environment + compile the renamer binary
make binary-clean   # Remove SPC build artifacts to free space
```

The output is `renamer` in the repository root. Install it on a compatible Linux host:

```bash
chmod +x renamer
sudo mv renamer /usr/local/bin/
renamer --version
```

### Releases

`make version` requires a clean worktree/index and a configured remote upstream. It rejects existing local/remote release tags before changing `version`, runs the complete Docker-backed `make test` on the proposed version, rejects unexpected gate side effects, and commits only `version` with an imperative subject and DCO. It publishes exactly the configured branch and new tag using one atomic push pinned to the tested release commit; unsupported atomicity fails without separate fallback pushes.

A gate failure retains the proposed local version change for inspection and creates no release commit/tag/push. Commit/tag or publication failures retain the relevant local state. Inspect local history and actual remote refs before retrying: a lost server acknowledgement can report failure even when both remote refs were updated. The helper never stashes, resets or removes foreign work. Regression tests use temporary repositories, a local bare remote and stub Make/controlled Git failures; they publish no project release.

### Other targets

| Target              | Description                                           |
|---------------------|-------------------------------------------------------|
| `make docker-build` | Build the Docker image.                               |
| `make runtime-build` | Build immutable production code, vendor and DI container. |
| `make runtime-cache-clear` | Purge current-UID media JSON in the runtime volume. |
| `make cache-prune` / `make runtime-cache-prune` | Remove cache entries for non-visible source paths; mount the expected collection. |
| `make metadata-cache-benchmark COUNT=100000` | Profile synthetic metadata load/flush/prune in isolated Docker. |
| `make bash`         | Open a bash shell inside the buildbox container.      |
| `make update`       | Update Composer dependencies.                         |
| `make version`      | Create a new version release.                         |
