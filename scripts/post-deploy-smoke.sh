#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
ENV_FILE="${ENV_FILE:-.env.production}"
APP_SERVICE="${APP_SERVICE:-app}"
REDIS_SERVICE="${REDIS_SERVICE:-redis}"
WEB_URL="${WEB_URL:-http://localhost/up}"
CHECK_RETRIES="${CHECK_RETRIES:-15}"
CHECK_DELAY_SECONDS="${CHECK_DELAY_SECONDS:-2}"
SUPERVISOR_CONFIG="${SUPERVISOR_CONFIG:-/etc/supervisor/conf.d/supervisord.conf}"

compose() {
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

supervisorctl_in_app() {
  compose exec -T "$APP_SERVICE" supervisorctl -c "$SUPERVISOR_CONFIG" "$@"
}

run_check() {
  local label="$1"
  shift
  local attempt

  for attempt in $(seq 1 "$CHECK_RETRIES"); do
    if "$@"; then
      printf '[PASS] %s\n' "$label"
      return 0
    fi

    if [ "$attempt" -lt "$CHECK_RETRIES" ]; then
      sleep "$CHECK_DELAY_SECONDS"
    fi
  done

  printf '[FAIL] %s\n' "$label" >&2

  return 1
}

check_web() {
  curl -fsS "$WEB_URL" >/dev/null
}

check_db() {
  compose exec -T "$APP_SERVICE" php artisan migrate:status --no-ansi >/dev/null
}

check_redis() {
  [ "$(compose exec -T "$REDIS_SERVICE" redis-cli ping | tr -d '\r')" = 'PONG' ]
}

check_storage() {
  compose exec -T "$APP_SERVICE" sh -lc '
    set -eu

    temp_file="storage/app/temp/.post-deploy-smoke.$$"
    public_file="storage/app/public/.post-deploy-smoke.$$"
    log_file="storage/logs/.post-deploy-smoke.$$"

    touch "$temp_file" "$public_file" "$log_file"
    rm -f "$temp_file" "$public_file" "$log_file"
  ' >/dev/null
}

check_queue_workers() {
  local output

  output="$(supervisorctl_in_app status all)"

  printf '%s\n' "$output" | awk '
    /^queue-worker:/ {
      found = 1
      if ($2 != "RUNNING") {
        failed = 1
      }
    }
    END {
      exit(found && ! failed ? 0 : 1)
    }
  '
}

check_scheduler() {
  supervisorctl_in_app status scheduler | awk '
    /^scheduler/ {
      found = 1
      if ($2 == "RUNNING") {
        healthy = 1
      }
    }
    END {
      exit(found && healthy ? 0 : 1)
    }
  '
}

check_schedule_registration() {
  local output

  output="$(compose exec -T "$APP_SERVICE" php artisan schedule:list --no-ansi)"

  grep -Fq 'calendar:sync' <<<"$output" &&
    grep -Fq 'media:cleanup-temp-files --hours=24' <<<"$output" &&
    grep -Fq 'media:cleanup-unpublished-section-assets --hours=48' <<<"$output" &&
    grep -Fq 'scripture:refresh-passages' <<<"$output"
}

main() {
  local failed=0

  printf 'Running production post-deploy smoke checks with %s and %s\n' "$COMPOSE_FILE" "$ENV_FILE"

  run_check 'Web responds on /up' check_web || failed=1
  run_check 'Application can reach the database' check_db || failed=1
  run_check 'Redis queue backend responds' check_redis || failed=1
  run_check 'Writable storage volumes are writable' check_storage || failed=1
  run_check 'Queue workers are running under Supervisor' check_queue_workers || failed=1
  run_check 'Scheduler is running under Supervisor' check_scheduler || failed=1
  run_check 'Expected scheduled commands are registered' check_schedule_registration || failed=1

  if [ "$failed" -ne 0 ]; then
    printf 'Production post-deploy smoke checks failed.\n' >&2
    exit 1
  fi

  printf 'Production post-deploy smoke checks passed.\n'
}

main "$@"
