#!/bin/bash
# The round's cutting loop, inside round-tools (the clone's code). Start once:
#   docker exec -d -u sail -w /var/www/html crockenhill-round-round-tools-1 \
#       env ROUND_SNAPSHOT=<dir> bash scripts/historic-round/autotick.sh
#
# Every 10 minutes: when the watcher has not stopped and no queue is paused, cut whatever is ready
# among the runs listed in <snapshot dir>/extract-runs.txt, minus the runs watch-state.json parks.
# The list is always explicit; an empty one dispatches nothing (rerun-extract refuses it besides).
# Logs each pass to storage/logs/round-autotick/<UTC>.log and latest.log.
set -uo pipefail
cd /var/www/html || exit 1
DIR="storage/app/private/${ROUND_SNAPSHOT:?set ROUND_SNAPSHOT}"
LOG=storage/logs/round-autotick
mkdir -p "$LOG"
echo $$ > "$LOG/pid"

while true; do
    T=$(date -u +%Y%m%dT%H%M%SZ)
    {
        echo "== $T"
        state=$(php scripts/historic-round/queue-state.php | tail -1)
        echo "$state"
        if [ -f "$DIR/watch-stop.json" ]; then
            echo "watcher stopped: dispatching nothing"
        elif ! echo "$state" | grep -q "^all queues: 0 paused"; then
            echo "queues paused: dispatching nothing"
        else
            runs=$(php -r '
                $listed = array_filter(array_map("intval", preg_split("/\s+/", trim((string) @file_get_contents($argv[1]."/extract-runs.txt")))));
                $parked = array_map("intval", (array) ((json_decode((string) @file_get_contents($argv[1]."/watch-state.json"), true) ?: [])["parked"] ?? []));
                echo implode(" ", array_values(array_diff($listed, $parked)));' "$DIR")
            if [ -z "$runs" ]; then
                echo "no runs listed to cut"
            else
                dry=$(php artisan historic-import:rerun-extract "$ROUND_SNAPSHOT/snapshot.json" $runs --max=500 --no-interaction 2>&1 | tail -1)
                echo "extract dry run: $dry"
                ready=$(echo "$dry" | sed -n 's/^\([0-9]*\) ready.*/\1/p')
                if [ -n "$ready" ] && [ "$ready" -gt 0 ]; then
                    php artisan historic-import:rerun-extract "$ROUND_SNAPSHOT/snapshot.json" $runs --max=500 --execute --no-interaction > "$LOG/$T-extract.txt" 2>&1
                    echo "extract executed: $(tail -1 "$LOG/$T-extract.txt")"
                fi
            fi
        fi
    } > "$LOG/$T.log" 2>&1
    cp "$LOG/$T.log" "$LOG/latest.log"
    sleep 600
done
