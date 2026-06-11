# Design: `local` endpoint for `lameco:sync`

**Date:** 2026-06-10
**Status:** Approved

## Problem

`lameco:sync` streams a database and uploaded files from one configured remote
host to another (e.g. production → staging). Both endpoints must be remote
hosts: each side is reached over SSH, and DB credentials are read from each
host's `{{deploy_path}}/shared/.env`. There is no way to use the developer's
local machine as an endpoint, even though separate tasks already exist for the
one-directional local cases (`lameco:db_download` / `lameco:download` and
`lameco:db_upload` / `lameco:upload`).

We want to extend `lameco:sync` so `local` can be chosen as the source or the
destination, in either direction:

- **local as source** — push the local database/files up to a remote host.
- **local as destination** — pull a remote host's database/files down to local.

## Endpoint model

An endpoint is either `local` or a configured `Host`. In code, `local` is
represented as a `null` host. This keeps a single uniform code path with no
inline four-way (local/remote × source/dest) branching in the sync body.

- The source/destination menus become `['local', ...$hostAliases]`.
- The existing "source ≠ destination" guard still applies and naturally
  prevents `local → local`.
- The "at least two hosts" guard relaxes to "at least one host," because
  `local ↔ one remote` is now a valid sync.

## Helpers (added to `src/functions.php`, alongside `buildSshCommand`)

All three accept `?\Deployer\Host\Host $host`, where `null` means the local
endpoint.

1. `wrapEndpointCommand(?Host $host, string $command): string`
   - remote → `buildSshCommand($host) . ' ' . escapeshellarg($command)`
   - local → `$command` unchanged

   Every streamed command runs through `runLocally()`. A remote endpoint is
   reached over SSH; a local endpoint executes directly in the local shell.
   The pipe `wrap(source, dump) . ' | ' . wrap(dest, import)` therefore covers
   all four local/remote combinations with no branching.

2. `readEndpointDbCredentials(?Host $host): array`
   - remote → `on($host, ...)` + `within('{{deploy_path}}/shared', ...)`,
     parsing `run('cat .env')` through `fetchEnv()` + `extractDbCredentials()`
   - local → `fetchEnv(file_get_contents('.env'))` + `extractDbCredentials()`

   Returns `[user, pass, name]` (entries may be `null` if extraction fails, as
   with the existing remote reads).

3. `getEndpointSharedPath(?Host $host): string`
   - remote → the resolved `{{deploy_path}}/shared`
   - local → the project root (`getcwd()`)

   Used as the `tar -C` base when streaming files and as the `mkdir -p` base on
   the destination.

## Sync body changes (`src/tasks.php`, `lameco:sync`)

- Build the choice list as `['local', ...$hostAliases]`; relax the host-count
  guard to require at least one configured host.
- Resolve `$sourceHost` / `$destHost` to `null` when the chosen value is
  `'local'`, otherwise to the `Host` via `$deployer->hosts->get(...)`.
- **Database block:** read credentials for both ends via
  `readEndpointDbCredentials`. The destination DROP/CREATE prep and the
  `dump | import` pipe each wrap their side with `wrapEndpointCommand`.
- **Files block:** the directory list comes from the *source* endpoint's
  `lameco_download_dirs` (local → read/parse the config directly; remote → via
  `on($sourceHost, ...)`). `tar` and `mkdir` use `getEndpointSharedPath` per
  side.
- **Service restarts:** invoke `lameco:restart_php` / `lameco:restart_supervisor`
  on the destination only when `$destHost !== null`. For a local destination
  these `sudo systemctl` / `supervisorctl` commands do not apply and are
  skipped.

## Error handling

- A missing or unreadable local `.env`, or credentials that cannot be
  extracted, produce `error()` + early return — matching the existing remote
  credential checks.
- The overwrite-confirmation box and warning text are unchanged; they simply
  display `local` as the source/destination label.

## Out of scope (YAGNI)

- No local asset rebuild, no database migrations, no new configuration keys.
  Local file paths and DB credentials reuse the conventions already established
  by `lameco:db_download` / `download` / `upload`.
