# AI Agents Project

A personal dashboard for managing Codex agents. The foundation provides Laravel, Inertia, React, TypeScript, a minimal Workshop at `/workshop`, and local PostgreSQL. Milestone 2 adds a verified Artisan harness for Codex App Server. Browser controls are reserved for milestone 3; see [the implementation checklist](docs/implementation-checklist.md).

## Start this configured WSL checkout

From the repository root in WSL:

```sh
bash scripts/postgres.sh start
composer run dev
```

Open [the workshop](http://localhost:8000/workshop) or [home](http://localhost:8000). Keep the command running for Laravel and Vite hot reload. Ctrl+C stops both web processes. PostgreSQL runs separately; stop it with `bash scripts/postgres.sh stop` and inspect it with `bash scripts/postgres.sh status`.

The current `.env` uses `ai_agents_dev` / `ai_agents_app` on `127.0.0.1:5433`. Its generated password stays private. The original environment, including the existing MySQL credentials, was preserved in ignored `.env.backup`. Unrelated environment settings and the application key were retained. Neither local file belongs in Git.

## Set up another WSL checkout

Keep the project in the Linux filesystem, for example `~/projects/ai-agents-project`. Run PHP, Composer, Node and npm inside WSL.

Requirements: PHP 8.3+ with `pdo_pgsql`, Composer 2, Node 20.19+ or 22.12+, npm, and PostgreSQL server/client binaries (`pg_config`, `initdb`, `pg_ctl`, `psql`). Check them before installing dependencies:

```sh
php -v
composer -V
node -v
npm -v
php --ri pdo_pgsql
psql --version
pg_config --bindir
composer install
npm ci
test -f .env || cp .env.example .env
```

On Ubuntu, missing PostgreSQL components can be installed with `sudo apt install postgresql postgresql-client libpq-dev php8.3-pgsql` when using the PHP 8.3 package repository. Match the PHP extension package to your installed PHP version. Package installation requires system permissions.

If `APP_KEY` is empty in the new `.env`, run `php artisan key:generate`. Retain an existing key. Configure PostgreSQL using one of these approaches.

### Project-local PostgreSQL (no sudo)

The helper initializes a private cluster under ignored `.local/postgres`, listens on loopback port 5433, and uses peer authentication for its owner and password authentication for TCP clients. It reuses the cluster on subsequent starts.

```sh
bash scripts/postgres.sh start
createuser --host="$PWD/.local/postgres" --port=5433 --pwprompt --no-superuser --no-createdb --no-createrole ai_agents_app
createdb --host="$PWD/.local/postgres" --port=5433 --owner=ai_agents_app ai_agents_dev
```

Create the role/database only once. Enter a new development password at the prompt and put the same password in `.env`. The other `DB_*` defaults in `.env.example` match this cluster. `PG_DEV_PORT=5434 bash scripts/postgres.sh start` can select another port if needed; update `DB_PORT` to match. Stop the cluster before changing its port. Do not remove `.local/postgres` to resolve a startup issue; check `.local/postgres/server.log` first.

### Existing system PostgreSQL

Alternatively, start your existing service with `sudo service postgresql start`. With database administrator access, create a dedicated login role and an owned development database:

```sh
sudo -u postgres createuser --pwprompt --no-superuser --no-createdb --no-createrole ai_agents_app
sudo -u postgres createdb --owner=ai_agents_app ai_agents_dev
```

Use the server's actual host/port (normally `127.0.0.1:5432`) and the chosen password in `.env`. Preserve any existing credentials before changing values. A configured `DB_URL` overrides individual connection settings.

### Finish setup

After checking that `.env` targets the intended development database:

```sh
php artisan config:clear
php artisan migrate
php artisan migrate:status
npm run build
composer run dev
```

`migrate` applies pending migrations without resetting existing data. PHP feature tests use the existing isolated SQLite in-memory configuration, not the development database. The foundation dev command starts Laravel and Vite; a queue worker is not needed yet. After dependency changes, rerun `npm ci` and the build.

Laravel Boost is installed for development. Follow `AGENTS.md`; `php artisan boost:install --no-interaction` refreshes its generated guidelines, skills and MCP configuration. MCP clients need to reload configuration to discover a newly installed server.

## Codex App Server spike (milestone 2)

Run from this checkout in a normal WSL terminal, with the same Linux user and authenticated Codex installation:

```sh
codex --version
codex login status
php artisan codex:spike
```

The default task asks for `SPIKE_OK` without tools. The command creates a private disposable Git repository under `/tmp/ai-agents-spike-*`, starts its own `codex app-server --listen stdio://`, initializes the protocol, checks the existing ChatGPT login, creates a thread and streams numbered activity, text and the terminal status. It prints the workspace, thread and turn IDs. Neither Laravel's web server nor PostgreSQL needs to be running for this command.

Verified with `codex-cli 0.171.0`, executable `/home/wesley/.nvm/versions/node/v20.19.5/bin/codex` (npm's `@openai/codex/bin/codex.js` wrapper). This requires Linux/WSL, PHP `pcntl`/`posix`, `/usr/bin/setsid` and Git. `CODEX_BINARY=/absolute/path/to/codex php artisan codex:spike` selects another executable without changing dependencies or `.env`.

Reuse the **workspace and thread printed by the same run** to verify resumption in a fresh App Server process:

```sh
php artisan codex:spike --workspace=/tmp/ai-agents-spike-REPLACE --thread=REPLACE \
  --task='What exact token did you reply with in the preceding turn? Reply only with that token. Do not use tools.'
```

The workspace retains its thread identity and rejects another session's thread. Starting a new thread in the same workspace replaces that retained identity. Temporary workspaces remain available until you remove them or the OS clears `/tmp`; Codex stores its own conversation history in its existing private home. The application database has no agent tables yet.

Exercise a real permission request in an interactive terminal:

```sh
php artisan codex:spike --timeout=300 \
  --task='Run python3 -c "print(73519)" exactly once using the shell tool in cwd. If permission is denied, stop and report DENIED. Do not try alternatives.'
```

Inspect the displayed command, workspace and request ID. Enter `allow 0`, `deny 0` or `cancel 0` **only if `0` is the displayed pending ID**; use the exact displayed JSON ID otherwise (including quotes for string IDs). `allow` authorizes that request once. There are no session grants or policy amendments. Permission-profile requests grant only the displayed requested profile for the current turn. Unattended runs with `--no-interaction`, or `--approval=deny`, reject requests. Unsupported server requests receive RPC error `-32601`. This CLI offered `accept` and `cancel` for the tested Python command: denial therefore used `cancel`, produced a `declined` command item and interrupted the turn.

Cancel a running turn, or press Ctrl+C during any run:

```sh
php artisan codex:spike --cancel-after=2 --no-interaction \
  --task='Run a shell command that sleeps for 30 seconds, then reply DONE. Work only in cwd.'
```

Exit codes: `0` for `completed`, `1` for a failed turn or harness/protocol error, `130` for `interrupted`, and `124` for a timeout. `--timeout=120` bounds a turn including time waiting for permission; `--request-timeout=20` bounds startup/individual RPC waits. Both accept positive seconds up to 3600. Cancellation sends `turn/interrupt`, waits up to five seconds for the terminal event, then closes the owned server. Cleanup closes stdin, allows one second for exit, then uses TERM/KILL for its owned process group and tracked descendants, checking Linux start times before signaling individual PIDs. It never searches for or kills processes by name. Ctrl+C was verified both at an approval prompt and with an approved sandboxed Python child running.

The harness reuses Symfony Process 7.4 already installed by Laravel; no new PHP/JS dependency, login provider or API key is introduced. It requires ChatGPT-managed CLI authentication and refuses other account types. Tasks use the CLI's configured model (observed: `gpt-6.1-sol`) and existing ChatGPT usage allowances. The child receives an allowlist of user/runtime environment variables, excluding application/database secrets and API keys. Account details, raw stderr and RPC error text are suppressed; stderr byte counts and safe failure codes remain visible. Model output and approval context go only to the terminal; do not put secrets in tasks or capture sensitive terminal output. Credentials remain in Codex's private setup, outside Laravel and frontend props.

Direct App Server startup **works independently of the CLI daemon**. No daemon, proxy, `--no-daemon`, login/logout or authentication-file edits are needed here. In the agent's restricted command sandbox, App Server exited with `Read-only file system`; live checks required permission to access existing Codex runtime files. Normal WSL execution passed. The CLI warned that no system bubblewrap was present and used its bundled copy successfully.

Inspect the installed interface and regenerate version-matched schemas after changing Codex:

```sh
command -v codex
codex app-server --help
codex app-server generate-json-schema --help
codex app-server generate-json-schema --out .local/codex-schema-0.171.0
```

The implementation follows the official [App Server guide](https://developers.openai.com/codex/app-server/) and the installed CLI's generated schemas: bidirectional JSON-RPC messages over newline-delimited stdio, omitting the `jsonrpc` header; `initialize`/`initialized`, then thread and turn methods. Installed help also lists Unix sockets, WebSockets and `off`; this spike exposes no listening socket. The protocol is CLI-version-sensitive, not a standalone version-negotiated contract.

Limitations: this is a single-turn local harness, not a durable worker or browser API. It does not replay work or reconnect a running turn after losing the process. Resumption requires the saved Codex history, original workspace and a new handshake; unresolved approvals must be obtained again, never replayed. SIGKILL, machine failure and children that detach before they can be observed require stronger external process supervision. File-change and permission-profile approvals have handlers, but live verification exercised command approvals only. Failed model turns and protocol/lifecycle errors are covered offline; no model failure was intentionally induced. No milestone 3 browser controls were added.

For milestone 3, use Laravel **SSE for ordered browser events plus authenticated HTTP POST controls**, with persisted event sequence numbers for reconnection. A supervised long-running Laravel worker should own each App Server's stdio connection, consume control messages and persist thread/turn/approval state; HTTP requests and browser tabs should not own the process. Use a process supervisor/cgroup for crash cleanup, and resume persisted threads deliberately after a restart without automatically resending tasks. Confirm this architecture before implementing browser controls.

## Verification

```sh
composer test
npm run types:check
npm run build
npm run format:check
vendor/bin/pint --dirty --format agent
composer validate --no-check-publish
bash -n scripts/postgres.sh
```

Use `php artisan test --filter=WorkshopTest` for a focused run. Use `npm run format` to format the frontend. HTTP tests disable Vite assets so they can run before a frontend build. The production build and live HTTP checks verify assets separately.

The Codex tests are offline. Command tests mock the App Server client; protocol/lifecycle tests launch a scripted PHP peer in `tests/Fixtures/codex-peer.php`, including a child in a separate process group. Neither launches Codex nor contacts a model:

```sh
php artisan test --compact tests/Feature/CodexSpikeTest.php tests/Unit/Services/Codex
```

Live Codex verification is opt-in through `php artisan codex:spike`; observed commands, protocol examples and outcomes are recorded in the checklist.

Verified on WSL2 with PHP 8.3.30, Composer 2.2.6, Node 20.19.5, npm 10.8.2 and PostgreSQL 14.24. Locked packages include Laravel 13.43, Inertia Laravel 3.7 / React adapter 3.11, React 19.3, TypeScript 7.0, Vite 8.3 and Tailwind 4.3. `concurrently` 9 supports this Node runtime; the skeleton's 10.x series required Node 22. Composer 2.2 emits dependency deprecation notices under PHP 8.3, but installation, tests and validation pass.

Manual Inertia integration follows the current official [server setup](https://inertiajs.com/docs/v3/installation/server-side-setup), [React/Vite setup](https://inertiajs.com/docs/v3/installation/client-side-setup) and [Laravel Vite guide](https://laravel.com/docs/13.x/vite), checked against installed package metadata and Boost's version-specific documentation. System fonts keep the build independent of external font downloads.

The checklist records verification results and limitations. The foundation and App Server spike are for local development; application authentication, browser agent controls and release readiness remain future work.
