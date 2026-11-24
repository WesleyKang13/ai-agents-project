# AI Agents Project

A local WSL dashboard for project-owned Codex agents, built with Laravel, Inertia, React and TypeScript. Sign in to **Projects**, register an existing server folder, then open its **Workshop** and create agents with independent roles, instructions, conversations, activity and controls.

## Start this configured checkout

From `/home/wesley/projects/ai-agents-project` in WSL:

```sh
bash scripts/postgres.sh start
php artisan config:clear
php artisan migrate --no-interaction
npm run build
composer run dev
```

Open the local app at the address printed by Artisan (normally port 8000), then sign in with your existing account. Login always opens Projects. The old `/workshop` address redirects there. `composer run dev` starts Laravel with multiple HTTP workers and Vite so SSE does not block controls. Ctrl+C stops those web processes; PostgreSQL runs separately (`bash scripts/postgres.sh status` / `stop`).

The additive project migration preserves existing users, credentials, agents, thread IDs, messages, events, controls and approvals. Existing agents become members of **Legacy workspace — …** projects owned by their original users. Sandbox folders retain a **Temporary** label; `/tmp` can be cleared by the OS. No account transfer, reset or reseed is needed. Stop an old `agents:work` process with Ctrl+C before migrating, then use the new explicit command below. The project migration is deliberately forward-only: rollback refuses to discard registered projects or conversations.

The existing private `.env` keeps PostgreSQL database `ai_agents_dev`, role `ai_agents_app` and port 5433. Keep its password, application key, `.env.backup`, `.local` data and Codex credentials private. Do not run `migrate:fresh`, reset the database, regenerate an existing application key or seed sample data.

This checkout’s upgrade is already applied. Verification reconnected Sage to its existing thread without a model task, then stopped the worker. Its missing disposable folder was recreated empty; the saved 35 messages and 16 turns remain. The PHP suite (98 tests), type check, build and formatting passed; interactive browser acceptance was not rerun because no browser driver is installed.

## Add a real project and its first agent

1. On Projects, choose **Add existing project** and enter a name.
2. In the server folder browser, open `/home/wesley/projects`, then navigate to an existing project folder and choose **Use this folder** → **Add project**.
3. Its Workshop initially has **0 agents**. Enter an agent name, optional role and custom instructions, then choose **Create agent**.
4. Select an agent to open its conversation/activity panel. **Back to Projects** returns to the list; **Create agent** adds another independent agent to the same project.

Folder selection refers to the machine running Laravel/Codex, not your browser’s computer. The browser loads one directory level at a time. Registration validates existence, canonical path, read/write/traverse access, allowed roots and symlink escapes. It rejects duplicate canonical folders for the same owner. It only registers the folder: no upload, copy, deletion, Git initialization or change to its files. Git is not required.

Configure permitted roots in `.env` (a colon-separated list on Linux):

```dotenv
PROJECT_ALLOWED_ROOTS=/home/wesley/projects
```

This is also the default in `config/agents.php`; run `php artisan config:clear` and restart workers after changing it. New project registration and execution revalidate the configured boundary. Legacy projects are exempt only from this root restriction, so existing saved workspaces continue to work; they still require their original canonical directory and permissions. Registered folders, owners and agent project assignments are immutable.

## Start the selected agent’s worker

In another WSL terminal **at this dashboard checkout**, run the exact command displayed under **Agent instructions and worker** in that agent’s panel:

```sh
php artisan agents:work AGENT_ID --user=OWNER_ID
```

Replace both placeholders with the numeric IDs from the panel. For the preserved Sage in this configured database:

```sh
php artisan agents:work 1 --user=2
```

The worker validates both the explicit agent and project owner. It never chooses the first saved agent or switches projects. One global process lock intentionally permits **one running worker at a time**: press Ctrl+C in its terminal before starting another agent’s command. Other agents remain saved and offline. This is a local operator command; `--user` checks the selection, not an additional login mechanism.

When the agent is Ready, send a task or follow-up. While it is working, use **Send steering**, **Stop task**, or the exact displayed permission request’s **Approve once / Deny** controls. Browser disconnection does not stop its worker. SSE reconnects to saved events. After an uncertain worker failure, restart that same command, inspect recovered history and choose **Resume conversation** if prompted. Tasks and approval grants are never automatically replayed.

The worker starts its own `codex app-server --listen stdio://` using the existing ChatGPT CLI login. Check `codex --version` and `codex login status` as your normal WSL user; the installed compatibility reference is CLI 0.171.0. `CODEX_BINARY` in `.env` can select an absolute executable. Each thread receives only that agent’s role/instructions through the App Server request; global Codex configuration and project `AGENTS.md` are untouched. No API key, daemon repair or new authentication provider is needed.

## Another WSL checkout

Use PHP 8.3+ (`pdo_pgsql`, `pdo_sqlite` for offline tests, `pcntl`, `posix`), Composer 2, Node 20.19+ or 22.12+, npm, PostgreSQL and an authenticated Linux Codex CLI. Install locked dependencies with `composer install` and `npm ci`. Copy `.env.example` only if `.env` is absent; generate an application key only if it is empty. Configure your dedicated PostgreSQL database, root paths and CLI executable before using the startup commands above.

`scripts/postgres.sh` can initialize a project-local PostgreSQL cluster on port 5433. For a new cluster only, create the app role/database with `createuser --host="$PWD/.local/postgres" --port=5433 --pwprompt --no-superuser --no-createdb --no-createrole ai_agents_app` and `createdb --host="$PWD/.local/postgres" --port=5433 --owner=ai_agents_app ai_agents_dev`; put the chosen password in `.env`. Existing clusters/databases must be reused. For a new local login, `php artisan workshop:user your-email@example.com` prompts for its password; existing users do not need this.

## Verification and limits

```sh
php artisan test --compact
npm run types:check
npm run build
npm run format:check
vendor/bin/pint --dirty --format agent
git diff --check
```

PHP tests use isolated SQLite memory databases. Codex worker/protocol tests use mocked clients or a scripted PHP peer and do not contact a model or modify the normal database. The optional `codex:spike` CLI harness remains for deliberate protocol diagnostics, not normal startup. The existing opt-in `tests/Fixtures/workshop-browser.mjs` requires an externally installed browser driver, an existing account file and explicit `WORKSHOP_PROJECT_ID` / `WORKSHOP_AGENT_ID`; it creates no records and sends no model task unless `WORKSHOP_LIVE_TASK=1` permits one tiny task. See [the checklist](docs/implementation-checklist.md) for actual verification evidence.

This step supports multiple saved agents with sequential manual execution. There are no queues, dependencies, automatic handoffs, concurrent scheduling, remote computer connections or production supervision. Agents in one project share its files while keeping separate conversations. Folder validation is an application boundary, not OS isolation from a hostile local filesystem administrator. SIGKILL/machine failures and rapidly detached children still need stronger external process supervision. File-change/profile approvals have offline coverage only; previous live verification exercised command approvals.
