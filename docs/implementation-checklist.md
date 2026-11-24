# Implementation checklist

Current scope: **Project-based organisation**, 24 November 2026. Stop after the local project/agent flow; concurrent scheduling, queues and handoffs remain future work.

## Existing foundation and browser integration

- [x] Laravel 13 / Inertia 3 / React 19 / TypeScript / Tailwind 4 application and local PostgreSQL.
- [x] Local password login; Codex App Server uses the existing authenticated WSL CLI (0.171.0).
- [x] Persistent thread, turns, messages, activity, SSE and authenticated controls.
- [x] Task/follow-up, steering, Stop, once-only approvals and uncertain-connection recovery implemented in the existing code.
- [x] User confirmed a real browser sign-in, Sage “Hello” task and model response before this change.
- [x] Prior milestone 2 evidence covered real CLI thread creation/resumption, command allow/deny, timeout, interrupt and process cleanup. It did not prove every later browser acceptance criterion.

The preceding documentation was stale: it still called browser controls future work. The implementation and working tree were inspected before this extension; no assumption was made that all milestone 3 live checks had finished.

## Project-based flow

- [x] Login opens Projects; list only owned projects with names, folders and agent counts.
- [x] Register an existing folder through a server-side browser; a new project has zero agents.
- [x] Default `PROJECT_ALLOWED_ROOTS=/home/wesley/projects`, configurable in `.env`.
- [x] Browse one level on demand; enforce canonical paths, permissions, traversal/escape rejection and per-owner canonical uniqueness.
- [x] No upload, file copying/deletion, initialization, Git requirement or remote connection.
- [x] Owned project Workshops list saved agents; create named agents with optional role and instructions.
- [x] Each agent uses its project’s workspace and its own thread/history/events/controls/approvals.
- [x] Thread start and resume apply only that agent’s developer instructions; global configuration and `AGENTS.md` stay unchanged.
- [x] Keep the existing conversation/activity UI, streaming, follow-ups, steering, Stop and approval controls.
- [x] Require `agents:work AGENT_ID --user=OWNER_ID`; validate the exact agent/project owner and retain the global lock for sequential execution.
- [x] Show the exact worker command and offline/recovery status in the selected agent’s panel.
- [x] Enforce user, project and agent ownership for pages, history, events and controls; reject cross-project agent selection.
- [x] Prevent reassignment of saved agents and project workspace/owner changes.
- [x] Add a forward-only migration, drop only the obsolete slot uniqueness, backfill labelled legacy projects without changing owners or conversations.
- [x] Make sample seeding inert; no automatic Sage or account creation.
- [x] Document migration, startup, folder registration, agent creation and selected worker commands in [README](../README.md).

## Verification for this change

Offline coverage includes folder registration without Git or file changes; lazy browsing; relative/missing/file/inaccessible/traversal/prefix/symlink/null-byte paths; canonical alias duplicates; guest and foreign ownership; cross-project selection; immutable conversations; independent histories/SSE/controls/approvals; explicit CLI targeting and the global worker lock. Worker tests cover start/resume instructions, untouched other-agent state, streaming, follow-ups, steering, Stop, allow/deny and recovery without replay.

The upgrade test creates the old schema in a separate SQLite memory database, inserts legacy records and compares every original field in users/agents/turns/messages/events/controls/approvals after migration. Routine tests create no accounts or agents in PostgreSQL.

Startup/upgrade commands:

```sh
bash scripts/postgres.sh start
php artisan config:clear
php artisan migrate --no-interaction
npm run build
composer run dev
# Separate terminal, using the IDs shown in the agent panel:
php artisan agents:work AGENT_ID --user=OWNER_ID
```

| Check | Result |
| --- | --- |
| `php artisan test --compact` | 98 passed, 736 assertions; isolated databases and offline Codex doubles/peer. |
| `npm run types:check`; `npm run build`; `npm run format:check` | Passed. |
| `vendor/bin/pint --dirty --format agent`; `git diff --check` | Passed. |
| `node --check tests/Fixtures/workshop-browser.mjs` | Passed. Updated opt-in fixture uses explicit existing project/agent IDs and defaults to read-only; at most one tiny task when explicitly enabled. |
| `php artisan route:list --except-vendor` | Project pages/creation/browser plus the existing agent history/SSE/control endpoints registered. |
| PostgreSQL `php artisan migrate --no-interaction` | Applied successfully. Before/after content checksums matched for users, messages, turns, events, controls and approvals. |
| Existing Sage worker: `php artisan agents:work 1 --user=2` | Connected to the saved thread, reconciled and reached idle; Ctrl+C stopped cleanly, cleared heartbeat/PID and showed offline. Still 35 messages / 16 turns; same owner 2, legacy project 1. No model task sent. |
| Interactive browser acceptance | Not rerun: no browser driver is installed in this session. No new dependency, verification account or test agent was added to the normal database. |

Sage’s old disposable `/tmp/ai-agents-workshop-1000` directory was absent in this session. It was recreated **empty**, without Git initialization or project-file changes, before the successful reconnect check. Saved conversation data remained intact. PostgreSQL is running; the verification worker was stopped. Local `.env`, PostgreSQL credentials and Codex configuration were not changed.

## Remaining limitations and later work

- [ ] Manually supervised single worker; no concurrency, scheduling, queues, dependencies or automatic handoffs.
- [ ] Stronger process supervision/cgroup containment for machine failure/SIGKILL and rapidly detached children.
- [ ] Live browser retest of the changed project flow; browser tooling was unavailable during implementation.
- [ ] Live file-change/profile approval verification; avoid model tasks merely to repeat existing offline controls coverage.
- [ ] Reviews/revisions, isometric room, usage-limit handling and release hardening remain separate milestones.
