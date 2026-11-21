# Implementation checklist

Current milestone: **2 — Codex App Server integration spike — implemented and verified, 21 November 2026.**

The foundation and App Server harness are for local WSL development. Agent execution now works through Artisan using the existing authenticated CLI. Browser controls and application authentication remain future work. Check a milestone only after its acceptance criteria have been exercised.

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

The next milestone, **Codex App Server integration spike**, is verified below.

## 2. Codex App Server integration spike

- [x] Record the installed Codex CLI version, supported App Server transport/protocol and authentication requirements in WSL.
- [x] From Laravel, start one local App Server process, complete initialization and observe its lifecycle without placing credentials in source control or frontend props.
- [x] Create/resume a thread, submit a small task in a disposable repository and capture ordered activity and completion events.
- [x] Exercise a permission request/response and cancellation; record protocol payloads, error behavior, process cleanup and reconnect constraints.
- [x] Acceptance: reproduce a complete task and a cancelled task from a documented Laravel-side harness, with observable events and no orphan process; use the findings to choose the browser event transport.

### Implementation and installed interface

`php artisan codex:spike` starts one dedicated App Server over stdio with Symfony Process 7.4.22 (already installed). It initializes, checks ChatGPT-managed CLI authentication, creates or resumes its own thread and submits one turn. The command displays numbered receive-order activity, model text, tool output, approval context and terminal status. It uses a private temporary Git repository; no frontend, database schema, credential file or authentication provider was changed. No dependency was added. The CLI's configured model was `gpt-6.1-sol`; live runs used the existing ChatGPT account's usage allowances rather than a separately configured API key.

Inspection commands actually run:

```sh
command -v codex
readlink -f /home/wesley/.nvm/versions/node/v20.19.5/bin/codex
codex --version
codex app-server --help
codex login status
codex app-server generate-json-schema --help
codex app-server generate-ts --help
codex app-server generate-json-schema --out .local/codex-schema-0.171.0
```

Observed: `codex-cli 0.171.0`; executable `/home/wesley/.nvm/versions/node/v20.19.5/bin/codex`, resolving to `.../lib/node_modules/@openai/codex/bin/codex.js`; `Logged in using ChatGPT`. Installed help lists `stdio://` (default), `unix://`, `unix://PATH`, `ws://IP:PORT` and `off`, and confirms `--stdio` aliases `--listen stdio://`. The harness uses neither daemon/proxy commands nor the CLI launcher's `--no-daemon` flag. Direct `codex app-server --listen stdio:// </dev/null` exited 0 outside the restricted agent sandbox. The earlier missing daemon socket was not reproduced by direct startup.

Read the current official [App Server guide](https://developers.openai.com/codex/app-server/), [Symfony 7.4 Process documentation](https://symfony.com/doc/7.4/components/process.html) and Boost's Laravel 13 Artisan signal/process documentation before implementation. Generated CLI schemas under ignored `.local/codex-schema-0.171.0` supplied exact installed request/response fields: v1 initialization and v2 thread/turn methods, plus command/file/permission approvals. No experimental API capability was required. The wire uses bidirectional JSON-RPC-shaped JSONL without the `jsonrpc` field. The CLI version and generated schemas are the compatibility reference; there is no negotiated protocol-version field in this handshake.

### Live verification (real CLI and model, not mocks)

| Command/check | Observed result |
| --- | --- |
| `php artisan codex:spike --no-interaction --timeout=60` | `SPIKE_OK`, `turn/completed` status `completed`, exit 0; passed again after final cleanup/ownership changes. Final workspace `/tmp/ai-agents-spike-703709c2c09c4730`, thread `01a1febf-da52-7661-9a2b-2e2cf009fd52`, owned group 23008. |
| `php artisan codex:spike --workspace=/tmp/ai-agents-spike-703709c2c09c4730 --thread=01a1febf-da52-7661-9a2b-2e2cf009fd52 --task='What exact token did you reply with in the preceding turn? Reply only with that token. Do not use tools.' --no-interaction --timeout=60` | Fresh owned process group 23606; resumed saved thread, recovered `SPIKE_OK`, status `completed`, exit 0. Earlier creation/resumption also passed on groups 9991/10197. |
| Interactive command approval (command below), then `allow 0` | Real `item/commandExecution/requestApproval` id 0; response `{"decision":"accept"}`; `serverRequest/resolved`; command output `73519`; turn `completed`, exit 0. Owned group 14427; no session grant or policy amendment. |
| Same tiny Python task with `--approval=deny --no-interaction --timeout=90` | Real approval request; advertised decisions omitted `decline`, so response was `{"decision":"cancel"}`. Command item `declined`; turn `interrupted`; exit 130. Owned group 15789. |
| `php artisan codex:spike --task='Run a shell command that sleeps for 30 seconds, then reply DONE. Work only in cwd.' --cancel-after=2 --no-interaction --timeout=30` | `turn/interrupt` acknowledged; terminal status `interrupted`, exit 130. Owned group 16674; cancellation happened before command execution. |
| Approval task with `--timeout=90`, no response entered | Real approval remained pending; timeout sent `turn/interrupt`; `turn/completed` status `interrupted`, exit 124. Group 11106. A second longer pending-approval timeout also passed (group 12819). |
| Sleep task with `--timeout=3600`, Ctrl+C at its approval prompt | `turn/interrupt`, pending request resolved, turn `interrupted`, exit 130. Owned group 17486. |
| Approved sandboxed Python child sleeping for 600 seconds, then Ctrl+C (command below) | Real one-request approval; child running in a separate sandbox/process group; terminal status `interrupted`, exit 130. Captured host tree PIDs 19822, 19834, 19947, 20398, 20402, 20403; all six were absent after cleanup, including Python 20403. The child's sandbox-visible PID was 2; it was never treated as a host PID. |
| Read-only host process inspection after runs | Final inspection found zero remaining recorded spike processes across groups 9991, 10197, 11106, 12819, 14427, 15789, 16674, 17486, 19822, 23008 and 23606, plus the separately grouped captured descendants. No unrelated session was signaled. |
| Boost database query after verification | Database `ai_agents_dev`, role `ai_agents_app`, port 5433, three existing migrations; no database changes needed. |

Exact interactive approval invocation used successfully:

```sh
php artisan codex:spike \
  --workspace=/tmp/ai-agents-spike-42fb3d7fe9e8fc4c \
  --thread=01a1fe9a-20ee-76d2-b403-a2ad66ab4492 \
  --task='Run python3 -c "print(73519)" exactly once using the shell tool in cwd. This is a permission check. If permission is denied, stop and report DENIED without trying alternatives.' \
  --timeout=3600
```

This historical workspace was created before thread-ownership metadata was added. For reproduction with the final code, omit `--workspace`/`--thread` to create a new owned thread, or use IDs printed by a new run. Enter `allow 0` only after inspecting the actual pending request; IDs vary. [README](../README.md#codex-app-server-spike-milestone-2) gives commands without historical IDs.

Exact approved-child interruption invocation:

```sh
php artisan codex:spike --timeout=3600 \
  --task='Run python3 -u -c "import os,time; print(\"CHILD_PID=\"+str(os.getpid()), flush=True); time.sleep(600)" exactly once in cwd, so I can verify interruption of a running child. Stop if approval is denied. Do not try alternatives.'
```

Wait for the real request, inspect and answer its ID, then press Ctrl+C while the child is running. The command uses private temporary workspaces, never the application checkout.

Representative verified payloads (IDs shortened; not a full transcript):

```json
{"id":1,"method":"initialize","params":{"clientInfo":{"name":"ai_agents_spike","title":"AI Agents Laravel Spike","version":"0.1.0"}}}
{"method":"initialized","params":{}}
{"id":2,"method":"account/read","params":{"refreshToken":false}}
{"id":3,"method":"thread/start","params":{"cwd":"/tmp/ai-agents-spike-…","sandbox":"read-only","approvalPolicy":"untrusted","approvalsReviewer":"user","developerInstructions":"…"}}
{"id":4,"method":"turn/start","params":{"threadId":"…","input":[{"type":"text","text":"Reply with exactly SPIKE_OK. Do not use tools."}]}}
{"id":0,"method":"item/commandExecution/requestApproval","params":{"threadId":"…","turnId":"…","itemId":"exec-…","command":"/bin/bash -lc 'python3 -c \"print(73519)\"'","cwd":"/tmp/ai-agents-spike-…","availableDecisions":["accept",{"acceptWithExecpolicyAmendment":{"execpolicy_amendment":["python3","-c","print(73519)"]}},"cancel"]}}
{"id":0,"result":{"decision":"accept"}}
{"id":5,"method":"turn/interrupt","params":{"threadId":"…","turnId":"…"}}
{"method":"turn/completed","params":{"threadId":"…","turn":{"id":"…","status":"interrupted","items":[],"error":null}}}
```

### Offline checks and failure behavior

Command tests mock `AppServerClient`. Protocol/lifecycle tests run a scripted PHP JSONL peer, **not Codex**, with real pipes, process exits and a descendant in a separate session/process group. They do not authenticate or contact a model. Coverage includes partial UTF-8/JSON, multiple messages, interleaved events and an approval request sharing an outgoing request's numeric ID, stale approvals, RPC errors, malformed/unmatched responses, stderr suppression, missing executables, unexpected/partial EOF, timeouts, credential environment filtering, denial and cleanup that leaves an unrelated process alive. Command tests distinguish completed/failed/interrupted outcomes and refuse application-workspace or foreign-thread resumption.

| Check | Result |
| --- | --- |
| `php artisan test --compact tests/Feature/CodexSpikeTest.php tests/Unit/Services/Codex` | 26 focused tests, 137 assertions (10 mocked command tests; 16 protocol/lifecycle/decoder tests). |
| `composer test` | 31 tests, 190 assertions, including the original foundation suite. |
| `vendor/bin/pint --dirty --format agent` | Passed. |
| `npm run types:check`; `npm run build`; `npm run format:check` | Passed; Workshop/frontend source unchanged. |
| `composer validate --no-check-publish`; `bash -n scripts/postgres.sh`; `git diff --check` | Passed; existing system Composer/PHP deprecation notices remain. |

The decoder bounds messages to 8 MiB and rejects malformed/incomplete JSON. Client responses are matched by request ID independently of server requests/events; terminal events arriving before RPC responses are retained. Raw stderr and error payloads are suppressed; RPC rejection codes, stderr byte counts and process exit codes remain observable. Timeout exit code is 124, failed/error is 1, interrupted is 130, successful completion is 0. Unsupported server requests receive `-32601`. Approval waits remain interruptible and count toward the turn deadline. Unattended runs deny requests; explicit grants apply only to the exact pending request, never a session or persistent policy.

Cleanup closes stdio, waits briefly, then signals only its owned group and tracked descendants. `/proc` ancestry/start-time checks cover helpers in separate groups and reduce PID-reuse risk. No process-name matching, daemon stop or global session cleanup is used. Workspaces and private Codex history remain for deliberate resumption. The workspace's retained thread ID prevents accidental control of another CLI session.

### Remaining limits and milestone 3 decision

- No milestone 2 functional blocker remains. The agent's restricted shell could not write Codex runtime files (`Read-only file system`); approved live execution outside that shell worked. Codex used its bundled bubblewrap after warning that the system binary was absent. No installer, daemon repair or authentication change was needed.
- File-change and permission-profile response handlers exist, but only command approvals were verified live. Failed model turns, startup failures and protocol faults were tested offline; no paid/API failure experiment was introduced. SIGTERM uses the same signal path as Ctrl+C, but the live signal check sent SIGINT.
- This is a single-turn Linux/WSL harness, not durable process supervision. SIGKILL/machine failure bypasses PHP cleanup; a rapidly detaching child can escape ancestry sampling. Use a supervisor/cgroup for stronger crash containment in the next milestone. No browser or multi-agent work was started.
- Resume uses Codex's saved history and a new initialization handshake. Pending approvals/events are not durable, and resuming does not automatically replay interrupted work. Never retry `turn/start` automatically after a lost response; reconcile state first. This harness retains only the latest thread per disposable workspace.
- **Recommended decision for milestone 3:** browser events via Laravel SSE, controls via authenticated HTTP POST. A supervised long-running Laravel worker owns stdio and persists ordered events, thread/turn state and exact pending approval IDs; browsers reconnect using persisted event sequence numbers. Browser disconnects should leave the worker running. Worker restart should explicitly reconcile/resume the saved thread without duplicate task submission or replayed approval grants. Confirm this approach when starting milestone 3; direct browser-to-App-Server WebSockets add unnecessary exposure and lifecycle coupling for this application.

Milestone 2 stops here. Changes are left uncommitted for review.

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
