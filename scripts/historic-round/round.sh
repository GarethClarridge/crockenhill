#!/bin/bash
# Control a historic round running from its frozen clone (docs/plans/FOLLOW-UP-ROUND-FROZEN-CLONE-2026-10-13.md §7.1).
# Runs on the host. Every command goes to the round's own `round-tools` container, so it runs the
# clone's code against the round's Redis databases; nothing here touches the working checkout.
#
#   ROUND_SNAPSHOT=<dir below storage/app/private> scripts/historic-round/round.sh <command>
#
#   status          paused or not, queue depths, watcher progress, recent stop
#   pause           pause every worker queue, then wait until no job is reserved (a cut can take 15 min)
#   resume          resume every queue; refused while the watcher's stop file stands
#   stop | start    pause and go idle, then stop the round's workers | start them and resume
#   watch           start the watcher for ROUND_SNAPSHOT (restart it to re-read watch-state.json)
#   artisan ...     run an artisan command in round-tools (e.g. historic-import:rerun-extract …)
set -euo pipefail

TOOLS=${ROUND_TOOLS:-crockenhill-round-round-tools-1}
PROJECT=${ROUND_PROJECT:-crockenhill-round}
in_tools() { docker exec -u sail -w /var/www/html "$TOOLS" "$@"; }
art() { in_tools php artisan "$@"; }
queues() { in_tools php artisan tinker --execute 'echo implode(" ", app(App\Services\HistoricMedia\HistoricRerunWatch::class)->queues());'; }
connection() { in_tools php artisan tinker --execute 'echo config("queue.default");'; }
snapshot_dir() { echo "storage/app/private/${ROUND_SNAPSHOT:?set ROUND_SNAPSHOT to the round snapshot directory}"; }
workers() { docker ps -a --filter "label=com.docker.compose.project=$PROJECT" --format '{{.Names}}' | grep -v round-tools; }

wait_idle() {
    while true; do
        reserved=$(in_tools php scripts/historic-round/queue-state.php --reserved)
        [ "$reserved" = "0" ] && { echo "idle: no job reserved"; return; }
        echo "$(date -u +%H:%M:%S) waiting for $reserved reserved job(s) to finish"
        sleep 30
    done
}

case "${1:-status}" in
    status)
        in_tools php scripts/historic-round/queue-state.php
        dir=$(snapshot_dir)
        in_tools php -r '$s = json_decode((string) @file_get_contents($argv[1]."/watch-status.json"), true) ?: []; $p = $s["progress"] ?? []; echo "watcher checked ", $p["checked_at"] ?? "never", ": ", json_encode($p["runs"] ?? []), "; failed jobs since start ", $p["failed_jobs_since_start"] ?? "?", PHP_EOL;' "$dir"
        in_tools test -f "$dir/watch-stop.json" && echo "WATCHER STOPPED: $dir/watch-stop.json" || true
        ;;
    pause)
        c=$(connection)
        for q in $(queues); do art queue:pause "$c:$q" >/dev/null; done
        echo "paused every queue"
        wait_idle
        ;;
    resume)
        dir=$(snapshot_dir)
        if in_tools test -f "$dir/watch-stop.json"; then
            echo "refused: the watcher stopped ($dir/watch-stop.json). Handle the stop (archive the file, fix or acknowledge it, restart the watcher) first." >&2
            in_tools cat "$dir/watch-stop.json" >&2
            exit 1
        fi
        c=$(connection)
        for q in $(queues); do art queue:resume "$c:$q" >/dev/null; done
        echo "resumed every queue"
        ;;
    stop)
        "$0" pause
        workers | xargs -r docker stop
        echo "round workers stopped"
        ;;
    start)
        workers | xargs -r docker start
        "$0" resume
        ;;
    watch)
        dir=$(snapshot_dir)
        in_tools pkill -f "historic-import:rerun-watch" || true
        docker exec -d -u sail -w /var/www/html "$TOOLS" sh -c "php artisan historic-import:rerun-watch ${ROUND_SNAPSHOT}/snapshot.json >> storage/logs/round-watch.log 2>&1"
        echo "watcher started for $ROUND_SNAPSHOT (log storage/logs/round-watch.log)"
        ;;
    artisan)
        shift
        art "$@"
        ;;
    *)
        sed -n '2,16p' "$0"
        exit 1
        ;;
esac
