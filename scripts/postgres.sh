#!/usr/bin/env bash
set -euo pipefail
umask 077

# An optional user-owned development cluster; never touches system PostgreSQL.
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cluster_root="$project_root/.local/postgres"
data_dir="$cluster_root/data"
postgres_bin="$(pg_config --bindir)"
port="${PG_DEV_PORT:-5433}"

case "${1:-status}" in
    start)
        mkdir -p "$cluster_root"
        if [[ ! -f "$data_dir/PG_VERSION" ]]; then
            "$postgres_bin/initdb" -D "$data_dir" --auth-local=peer --auth-host=scram-sha-256
        fi
        if "$postgres_bin/pg_ctl" -D "$data_dir" status >/dev/null 2>&1; then
            echo "Project PostgreSQL is already running."
        else
            "$postgres_bin/pg_ctl" -D "$data_dir" -l "$cluster_root/server.log" \
                -o "-h 127.0.0.1 -p $port -k '$cluster_root'" -w start
        fi
        ;;
    stop)
        "$postgres_bin/pg_ctl" -D "$data_dir" -m fast -w stop
        ;;
    status)
        "$postgres_bin/pg_ctl" -D "$data_dir" status
        ;;
    *)
        echo "Usage: bash scripts/postgres.sh {start|stop|status}" >&2
        exit 1
        ;;
esac
