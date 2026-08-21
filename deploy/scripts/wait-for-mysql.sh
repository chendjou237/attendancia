#!/usr/bin/env bash
#
# Polls the app's own MySQL connection (credentials read straight out of
# .env — no separate credential to maintain) until it accepts an
# authenticated ping, or a bounded timeout elapses.
#
# Why this exists: docs/setup.md §1 requires "MySQL up -> backfill runs ->
# live stream starts" on every boot, but two independently-enabled
# services (mysql, supervisor) are not ordered relative to each other, and
# a systemd "active" state doesn't mean InnoDB crash recovery has actually
# finished after an unclean shutdown (this server has no UPS — see §1).
# This script is the real readiness check; deploy/supervisor/hikvision-stream.conf
# runs it before starting the stream worker, so the worker never touches
# the database before it's genuinely ready.
#
# On timeout this exits non-zero and hands off to Supervisor's own
# autorestart/startretries loop (already relied on for the
# device-unreachable case — see hikvision-stream.conf) rather than
# looping forever itself: one retry mechanism, one log location.
#
# Usage: wait-for-mysql.sh
# Overrides (env vars win over .env, matching Laravel's own precedence):
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#   WAIT_FOR_MYSQL_TIMEOUT — seconds, default 300

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$APP_ROOT/.env"

env_value() {
    if [ -f "$ENV_FILE" ]; then
        { grep -E "^${1}=" "$ENV_FILE" || true; } \
            | tail -n1 \
            | cut -d '=' -f2- \
            | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
    fi
}

if ! command -v mysqladmin >/dev/null 2>&1; then
    echo "wait-for-mysql: mysqladmin not found on PATH — install the mysql client package" >&2
    exit 1
fi

DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
DB_DATABASE="${DB_DATABASE:-$(env_value DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

TIMEOUT="${WAIT_FOR_MYSQL_TIMEOUT:-300}"
POLL_INTERVAL=2
HEARTBEAT_EVERY=15

# MYSQL_PWD, not --password=, so the password never shows up in `ps` output
# on a shared box while this polls.
export MYSQL_PWD="$DB_PASSWORD"

echo "wait-for-mysql: waiting for ${DB_HOST}:${DB_PORT} (database: ${DB_DATABASE:-unset}), timeout ${TIMEOUT}s"

elapsed=0
next_heartbeat=$HEARTBEAT_EVERY

while true; do
    if mysqladmin ping \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USERNAME" \
        --connect-timeout=2 \
        --silent >/dev/null 2>&1; then
        echo "wait-for-mysql: MySQL is up after ${elapsed}s"
        exit 0
    fi

    if [ "$elapsed" -ge "$TIMEOUT" ]; then
        echo "wait-for-mysql: gave up after ${TIMEOUT}s — MySQL never became reachable" >&2
        exit 1
    fi

    if [ "$elapsed" -ge "$next_heartbeat" ]; then
        echo "wait-for-mysql: still waiting for MySQL... ${elapsed}s elapsed"
        next_heartbeat=$((next_heartbeat + HEARTBEAT_EVERY))
    fi

    sleep "$POLL_INTERVAL"
    elapsed=$((elapsed + POLL_INTERVAL))
done
