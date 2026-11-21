# AI Agents Project

A personal dashboard for managing Codex agents. The first milestone provides a Laravel application with an Inertia, React and TypeScript frontend, a minimal Workshop at `/workshop`, and local PostgreSQL. Agent connections and controls come next; see [the implementation checklist](docs/implementation-checklist.md).

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

Verified on WSL2 with PHP 8.3.30, Composer 2.2.6, Node 20.19.5, npm 10.8.2 and PostgreSQL 14.24. Locked packages include Laravel 13.43, Inertia Laravel 3.7 / React adapter 3.11, React 19.3, TypeScript 7.0, Vite 8.3 and Tailwind 4.3. `concurrently` 9 supports this Node runtime; the skeleton's 10.x series required Node 22. Composer 2.2 emits dependency deprecation notices under PHP 8.3, but installation, tests and validation pass.

Manual Inertia integration follows the current official [server setup](https://inertiajs.com/docs/v3/installation/server-side-setup), [React/Vite setup](https://inertiajs.com/docs/v3/installation/client-side-setup) and [Laravel Vite guide](https://laravel.com/docs/13.x/vite), checked against installed package metadata and Boost's version-specific documentation. System fonts keep the build independent of external font downloads.

The checklist records verification results and limitations. This milestone is intended for local development; agent execution, authentication and release readiness remain future work.
