# Configuration

[Quick start](../README.md#-quick-start) · [Runtime and safety](runtime-and-safety.md)

## Environment variables

The project uses a `.env` file (loaded by Docker Compose) for environment-specific settings. Copy `.env.dist` as a starting point:

```bash
cp .env.dist .env
```

| Variable   | Default         | Description                                                                 |
|------------|-----------------|-----------------------------------------------------------------------------|
| `USERID`   | `1000`          | User ID for the Docker container.                                           |
| `GROUPID`  | `1000`          | Group ID for the Docker container.                                          |
| `TIMEZONE` | `Europe/Berlin` | Default timezone for video files without timezone metadata.      |
| `MAX_DATE_DRIFT` | `7`     | Maximum date drift in days between source filename date and target date. Set to `0` to disable. |
| `MAX_COMPARISON_PAIRS` | `100000` | Positive finite pair-visit limit per capture group and cross-group video batch; excess reports incomplete analysis and fails. |
| `CANONICAL_FORMAT_PRIORITY` | `heic,heif,dng,arw,...` | Ordered format preference used for canonical selection; see [`.env.dist`](../.env.dist) for the full default. |
| `MERGE_THRESHOLD` | `0.06`  | Maximum RMSE (0.0–1.0) for merging visually similar files. Internal safe limits still cap the effective threshold. See `--merge-threshold`. |
| `CACHE_DIR` | Runtime-dependent | Shared host base for mutation locks, journals and media JSON. Source: project `.build/cache`; standalone: `$HOME/.cache/renamer`, or `<system temp>/renamer-cache` without `HOME`; Docker: `/cache` volume. Relative overrides resolve against the command’s working directory. |
| `MEDIA_DIR` | `./images` | Existing host directory bound at `/media` for runtime commands. |
| `MEDIA_READ_ONLY` | `false` | Read-only media mount for analysis; mutating commands need write access. |
| `PHP_MEMORY_LIMIT` | `1024M` | Positive finite PHP byte quantity (optionally K/M/G); unlimited and malformed values fail before startup. |
| `RUNTIME_MEMORY_LIMIT` | `2g` | Container RAM limit; swap limit equals RAM. |
| `RUNTIME_CPUS` | `2.0` | Container CPU allocation limit. |
| `RUNTIME_PIDS_LIMIT` | `128` | Container process/thread limit. |
| `RUNTIME_TMP_SIZE` | `512m` | Bounded temporary tmpfs shared by native tools and PHP. |
| `FILE_LINK_ROOT` | *(empty)* | Source path as seen inside Docker/NAS (e.g. `/media` for the runtime). |
| `FILE_LINK_BASE` | *(empty)* | Same path as seen from the terminal host (e.g. `Z:\Photos`). |
| `FILE_LINK_PROTOCOL` | *(empty)* | URI scheme for clickable links: empty = `file://` (opens directory), `photo-select` = custom protocol (opens Explorer with file selected). |

Explicit configuration is validated before media changes: `MAX_DATE_DRIFT` / `--max-date-drift` requires a non-negative integer, and only a valid explicit `0` disables the drift check. `MERGE_THRESHOLD` / `--merge-threshold` requires a finite number from 0 to 1; internal safety caps still apply. Unsupported `TIMEZONE` / `--timezone` values fail visibly. CLI values take precedence over environment values, and absent optional settings keep their documented defaults. Docker forwards canonical format priority and merge threshold to both runtime and development services.

Analysis checks leave reporting and cleanup headroom inside the effective PHP limit: one eighth of the budget, bounded to 8–64 MiB. Growth checkpoints in source identities, file collection, capture grouping, pairing and plan construction stop with an explicit incomplete-analysis error before media changes; a final gate checks again before execution. On this stop, EXIF preserves the previous disk caches instead of serializing partial snapshots. A single large allocation between checkpoints, native memory, kernel OOM or failure after mutation starts can still interrupt a run; inspect its journal and actual files before replanning. The reserve does not prove that an arbitrary collection fits the default budget.

### Clickable file paths in terminal output

When `FILE_LINK_ROOT` and `FILE_LINK_BASE` are set, file paths in the output become clickable (Ctrl+Click) in terminals that support OSC 8 hyperlinks.

| Terminal | `file://` links | `photo-select://` links |
|----------|----------------|------------------------|
| PhpStorm | Yes | Yes |
| VS Code | Yes | Yes |
| iTerm2 (macOS) | Yes | n/a |
| Windows Terminal | No (`file://` blocked) | Yes (with protocol handler) |

**Basic setup (opens the file's parent directory):**

```env
FILE_LINK_ROOT=/media
FILE_LINK_BASE=Z:\Photos
```

**Advanced setup — Windows Explorer with file selected:**

```env
FILE_LINK_ROOT=/media
FILE_LINK_BASE=Z:\Photos
FILE_LINK_PROTOCOL=photo-select
```

The `photo-select` protocol requires a one-time setup on Windows. Open a PowerShell window (**not** as Administrator) and run:

```powershell
powershell -ExecutionPolicy Bypass -File \\YOUR-NAS-IP\docker\renamer\scripts\windows\install-protocol.ps1 -HandlerPath "\\YOUR-NAS-IP\docker\renamer\scripts\windows\photo-select.ps1"
```

> **Note:** Use the NAS IP address, not hostname, if DNS resolution is unreliable. The entire command must be on one line.

This registers a `photo-select://` URI handler that calls `explorer.exe /select` to highlight the clicked file. A VBS wrapper (`photo-select.vbs`) prevents the PowerShell window from flashing.

To uninstall:

```powershell
powershell -ExecutionPolicy Bypass -File \\YOUR-NAS-IP\docker\renamer\scripts\windows\install-protocol.ps1 -Uninstall
```

**Troubleshooting clickable links:**

| Problem | Cause | Solution |
|---------|-------|----------|
| Links not clickable | Terminal doesn't support OSC 8 | Use PhpStorm, VS Code, or iTerm2 |
| "This link type is not supported" | Windows Terminal blocks `file://` | Set `FILE_LINK_PROTOCOL=photo-select` and install the protocol handler |
| PowerShell window flashes briefly | VBS wrapper not registered | Re-run `install-protocol.ps1` (it auto-detects `photo-select.vbs`) |
| Explorer opens but file not found | Path mapping mismatch | Verify `FILE_LINK_ROOT` matches the Docker/NAS source path and `FILE_LINK_BASE` matches the Windows drive letter or mount point |
| Install script produces no output | NAS hostname not resolvable | Use IP address instead of hostname in the script path |
| Handler path contains a PowerShell provider prefix | Invalid registered handler path | Re-run `install-protocol.ps1` with an ordinary filesystem path |
| Links open photo viewer instead of Explorer | `FILE_LINK_PROTOCOL` not set | Set `FILE_LINK_PROTOCOL=photo-select` in `.env` |
