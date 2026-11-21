# Implementation checklist

Current milestone: **Application foundation — implemented and verified, 21 November 2026.**

The foundation is for local WSL development. Agent execution and account integration have not been implemented. Check a milestone only after its acceptance criteria have been exercised.

## 1. Application foundation

- [x] Inspect repository instructions, Git status, configuration, dependencies and installed tooling before changes. The starting working tree was clean.
- [x] Install Laravel Boost and generate/read the application guidelines, skills and MCP configuration.
- [x] Verify package compatibility against current official documentation, installed versions and npm peer/engine metadata.
- [x] Integrate Inertia 3, React 19 and TypeScript with Laravel routes/controllers, Vite 8 and the existing Tailwind 4 setup.
- [x] Provide Home navigation and a responsive cream/sage Workshop at `/workshop`, with a clear empty state and Laravel-supplied page props.
- [x] Check PHP, Composer, Node, npm, `pdo_pgsql` and PostgreSQL availability.
- [x] Configure dedicated PostgreSQL role/database; retain original local environment credentials and unrelated values.
- [x] Apply the existing migrations non-destructively and verify the active database, role, port and migration status.
- [x] Document WSL setup/startup, private configuration and local PostgreSQL lifecycle.
- [x] Pass the existing PHP suite plus Inertia feature tests, TypeScript checking, production build and formatting checks.
- [x] Verify direct loads, refreshes, Inertia JSON responses, Laravel page data and asset delivery through the running Laravel application in development and with built assets.

Verification evidence:

| Command/check | Actual result |
| --- | --- |
| `php -v`, `composer -V`, `node -v`, `npm -v` | PHP 8.3.30; Composer 2.2.6; Node 20.19.5; npm 10.8.2 |
| `php -m`, `psql --version`, `pg_lsclusters`, `pg_isready` | `pdo_pgsql` available; PostgreSQL 14.24 installed; system cluster initially stopped |
| `composer require laravel/boost --dev`; `php artisan boost:install --no-interaction` | Boost 2.10.4 installed; guidelines, skills and MCP configuration generated successfully after rerunning with access to protected agent directories |
| `composer require inertiajs/inertia-laravel:^3.0` | Laravel adapter 3.7.0 installed; Laravel remains at 13.43.0 |
| `npm install`; `npm install --save-dev concurrently@^9.2.1` | React 19.3.1; Inertia React/Vite 3.11.0; TypeScript 7.0.2; Vite 8.3.8; Tailwind 4.3.5; compatible concurrently 9.2.5; audit reported zero vulnerabilities |
| `bash scripts/postgres.sh start` | Private project cluster on loopback port 5433; a repeated start safely reports already running |
| `php artisan migrate --no-interaction` | All 3 existing migrations applied; second run: “Nothing to migrate” |
| `php artisan migrate:status --no-interaction` | Users, cache and jobs migrations all marked Ran, batch 1 |
| Boost `database-query` | Active database `ai_agents_dev`, role `ai_agents_app`, port 5433; role is neither superuser, database creator nor role creator |
| `composer test` | 5 tests passed, 53 assertions, including both original tests |
| `php artisan test --compact tests/Feature/WorkshopTest.php` | 3 tests passed, 51 assertions |
| `npm run types:check` | Passed (`tsc --noEmit`) |
| `npm run build` | Passed; production manifest, page chunks, application JS and CSS generated |
| `npm run format:check` | Passed; all matched frontend/configuration files formatted |
| `vendor/bin/pint --dirty --format agent` | Passed |
| `composer validate --no-check-publish` | Valid; system Composer emits deprecation notices under PHP 8.3 |
| `bash -n scripts/postgres.sh`; `git diff --check` | Passed |
| `php artisan route:list --except-vendor`; `php artisan view:cache --no-interaction` | Home and Workshop named GET routes registered; Blade compilation passed |
| `composer run dev` | Laravel and Vite both started; Ctrl+C stopped both |
| Live HTTP requests with a cookie jar | `/`: 200; `/workshop` direct load and repeated load: 200; page bootstrap contains project name, navigation, empty agents and milestone from Laravel |
| Live `X-Inertia` request using the initial asset version | 200 JSON, `X-Inertia: true`, Workshop component and expected props |
| Development asset requests | Vite client, TSX entry, Workshop module and CSS all returned 200; development SSR also returned the empty-state markup |
| Built asset requests with Vite stopped | Home/Workshop HTML and Inertia JSON returned 200; every manifest JS/CSS asset returned 200; no Vite URL in the HTML |
| Unknown route | 404 in development and built-asset checks |
| `git check-ignore` | `.env`, `.env.backup` and PostgreSQL data are ignored |

Setup fixes: removed the newer-Composer-only `@no_additional_args` marker from the test script for Composer 2.2 compatibility. The dev script uses installed `concurrently` rather than relying on an unavailable optional terminal launcher. Tests disable Vite asset rendering and send the actual Inertia asset version for JSON navigation.

### Remaining blockers and verification limits

- No functional blocker remains for this local foundation. Private PostgreSQL configuration is already in `.env`; no credential is needed to start this configured checkout.
- Browser visual inspection, JavaScript hydration, keyboard interaction and viewport checks were **not executed**: no supported browser automation tool was exposed in this session. Live HTTP/SSR, Vite module delivery, built asset delivery and feature tests passed. Manually open `/workshop`, follow Home/Workshop links, refresh and inspect narrow/wide layouts and the browser console.
- The system PostgreSQL service was not changed: `sudo -n service postgresql start` requires the user's sudo password. The project-local cluster avoids that requirement. Choosing system PostgreSQL later requires service/admin access and updating `.env` to its actual port.
- The application retains local development settings and has no authentication or Codex credentials yet. The generated agent tooling configuration may require an MCP client restart to become available.

Next acceptance test: **Codex App Server integration spike**, below.

## 2. Codex App Server integration spike

- [ ] Record the installed Codex CLI version, supported App Server transport/protocol and authentication requirements in WSL.
- [ ] From Laravel, start one local App Server process, complete initialization and observe its lifecycle without placing credentials in source control or frontend props.
- [ ] Create/resume a thread, submit a small task in a disposable repository and capture ordered activity and completion events.
- [ ] Exercise a permission request/response and cancellation; record protocol payloads, error behavior, process cleanup and reconnect constraints.
- [ ] Acceptance: reproduce a complete task and a cancelled task from a documented Laravel-side harness, with observable events and no orphan process; use the findings to choose the browser event transport.

## 3. One agent controlled from the browser

- [ ] Add one real agent and persist its identity, thread and current task.
- [ ] Open its chat/activity panel; send a task, stream progress and show completion or failure.
- [ ] Send a mid-task message and cancel a running task from the browser.
- [ ] Acceptance: complete, interrupt and resume a real task from `/workshop`, then refresh without losing the persisted agent/task state.

## 4. Multiple agents and task queues

- [ ] Manage multiple independent agent processes, chats and task histories.
- [ ] Persist ordered queues and dispatch only eligible tasks with explicit concurrency limits.
- [ ] Prevent duplicate dispatch and isolate events/controls by agent.
- [ ] Acceptance: run two agents concurrently while a queued task waits; verify order and recovery after refresh/restart.

## 5. Permissions, reviews and revision requests

- [ ] Surface permission requests with enough context for an informed approval or denial.
- [ ] Support code review, review findings and revision requests tied to a task/change.
- [ ] Persist decisions and prevent stale approvals from authorizing a different request.
- [ ] Acceptance: approve and deny real permission requests, then review a change and request a verified revision from the browser.

## 6. Dependencies and automatic handoffs

- [ ] Model dependencies, validate cycles and define failure/cancellation behavior.
- [ ] Dispatch dependent tasks only after required results are ready.
- [ ] Transfer explicit outputs/context between agents and show handoff history.
- [ ] Acceptance: finish an agent task that unblocks another exactly once; a failed prerequisite must block its dependent task with an actionable explanation.

## 7. Isometric robot workshop

- [ ] Build the shared isometric room, individual stations and cute animated robots.
- [ ] Add task labels and clear idle, working, waiting, permission and error states.
- [ ] Open the correct agent's chat/activity/queue/controls by clicking its robot.
- [ ] Provide responsive and keyboard-accessible alternatives, plus reduced-motion support.
- [ ] Acceptance: identify and control multiple real agents from the room on narrow/wide screens without relying on animation or color alone.

## 8. Usage limits, recovery and release verification

- [ ] Detect supported account usage/rate-limit signals; pause dispatch while keeping queued work safe.
- [ ] Show when/why dispatch is paused and provide a deliberate resume path.
- [ ] Recover from process crashes, disconnected clients and application restarts without duplicate work.
- [ ] Verify authentication/access boundaries, secret handling, supported runtime versions, migration/backup procedures and release configuration.
- [ ] Acceptance: simulate a usage limit and a server restart, verify no new dispatch while paused, then resume each eligible task once; pass the complete release checks and browser acceptance flow.
