#!/usr/bin/env bash
set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
ENV_FILE="${ENV_FILE:-.env.production}"
APP_SERVICE="${APP_SERVICE:-app}"
REDIS_SERVICE="${REDIS_SERVICE:-redis}"
# The cheap liveness probe hits the app container's plain-HTTP port directly, so
# it works before TLS/DNS and without leaving the host.
WEB_URL="${WEB_URL:-http://localhost/up}"
# Canaries must exercise the real public edge (Caddy vhost, TLS, redirects) — the
# same origin supplied by APP_URL/CANARY_BASE_URL in the deploy workflow.
# Requesting http://localhost here never matches the crockenhill.org vhost, so Caddy
# returns redirects instead of the app and every non-redirect canary fails. The
# deploy workflow passes the real APP_URL; this default keeps a standalone run honest.
CANARY_BASE_URL="${CANARY_BASE_URL:-https://crockenhill.org}"
CANARY_MAX_TIME="${CANARY_MAX_TIME:-20}"
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

# Request a single canary URL `hits` times against the live site, asserting the
# HTTP status (no redirect-following) and, when given, a body marker. Cached
# routes are hit twice so the second request rehydrates the read model through
# unserialize() under cache.serializable_classes.
check_one_canary() {
  local url="$1" expected="$2" hits="$3" marker="$4"
  local attempt code body_file
  body_file="$(mktemp)"

  for attempt in $(seq 1 "$hits"); do
    code="$(curl -s -o "$body_file" -w '%{http_code}' --max-time "$CANARY_MAX_TIME" "${CANARY_BASE_URL}${url}")" || {
      rm -f "$body_file"
      return 1
    }

    if [ "$code" != "$expected" ]; then
      rm -f "$body_file"
      return 1
    fi

    if [ -n "$marker" ] && ! grep -qF "$marker" "$body_file"; then
      rm -f "$body_file"
      return 1
    fi
  done

  rm -f "$body_file"

  return 0
}

# Generate the canary manifest from the app container, then verify each public
# route type against the live site. Runs after `cache:clear`, so the first hit
# populates the cache and the second reads it back.
check_route_canaries() {
  local manifest url expected hits marker failed=0

  manifest="$(compose exec -T "$APP_SERVICE" php artisan monitoring:canaries --no-ansi | tr -d '\r')" || {
    printf '[FAIL] %s\n' 'Generate route canary manifest' >&2
    return 1
  }

  if [ -z "$manifest" ]; then
    printf '[FAIL] %s\n' 'Route canary manifest was empty' >&2
    return 1
  fi

  while IFS=$'\t' read -r url expected hits marker; do
    case "$url" in
      /*) ;;          # only process manifest rows; skip any stray stdout
      *) continue ;;
    esac

    run_check "Canary ${url} (x${hits}, expect ${expected})" \
      check_one_canary "$url" "$expected" "$hits" "$marker" || failed=1
  done <<MANIFEST
$manifest
MANIFEST

  return "$failed"
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

# Horizon replaced the per-queue `queue-worker:*` supervisor programs (June 2026
# review, Phase P1). Supervisor only proves the master process exists, so also
# ask Horizon itself — `horizon:status` reports "running" only once the master
# supervisor loop is processing, and catches a paused or inactive Horizon that
# supervisorctl would still show as RUNNING.
check_horizon() {
  supervisorctl_in_app status horizon | awk '
    /^horizon/ {
      found = 1
      if ($2 == "RUNNING") {
        healthy = 1
      }
    }
    END {
      exit(found && healthy ? 0 : 1)
    }
  ' || return 1

  compose exec -T "$APP_SERVICE" php artisan horizon:status --no-ansi | grep -qiF 'is running'
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
    grep -Fq 'scripture:refresh-passages' <<<"$output" &&
    grep -Fq 'health:check' <<<"$output" &&
    grep -Fq 'model:prune' <<<"$output" &&
    grep -Fq 'horizon:snapshot' <<<"$output" &&
    grep -Fq 'backup:clean' <<<"$output" &&
    grep -Fq 'backup:run' <<<"$output" &&
    grep -Fq 'backup:monitor' <<<"$output"
}

main() {
  local failed=0

  printf 'Running production post-deploy smoke checks with %s and %s\n' "$COMPOSE_FILE" "$ENV_FILE"

  run_check 'Web responds on /up' check_web || failed=1
  run_check 'Application can reach the database' check_db || failed=1
  run_check 'Redis queue backend responds' check_redis || failed=1
  run_check 'Writable storage volumes are writable' check_storage || failed=1
  run_check 'Horizon is running under Supervisor' check_horizon || failed=1
  run_check 'Scheduler is running under Supervisor' check_scheduler || failed=1
  run_check 'Expected scheduled commands are registered' check_schedule_registration || failed=1

  # Reports per-canary PASS/FAIL itself, so it is not wrapped in run_check.
  check_route_canaries || failed=1

  if [ "$failed" -ne 0 ]; then
    printf 'Production post-deploy smoke checks failed.\n' >&2
    exit 1
  fi

  printf 'Production post-deploy smoke checks passed.\n'
}

main "$@"
