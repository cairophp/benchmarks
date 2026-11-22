#!/usr/bin/env bash
# A request shaped like an API's (server.php, real.lua) under several
# configurations in turn, in rotating order: requests per second of each, one
# line per round. A configuration is a label and what to set for it:
#
#   ./rounds.sh 20 "base=BIN=old/cairo" "new=BIN=../../cairo/bin/cairo" > out.log
#   ./rounds.sh 8 "none=" "log=REAL_MW=log" "all=REAL_MW=log,id,timeout"
#   ./rounds.py out.log base new
#
# BIN is the binary (default ../../cairo/bin/cairo); the rest goes to the
# server and to wrk's script: REAL_MW, REAL_POST, REAL_STATIC, REAL_PUBLIC,
# REAL_UPLOAD_KB, REAL_BIG_KB, REAL_NO_RID, GOGC. The server runs on CPUs 0
# and 1 with 4 PHP threads, wrk with 64 connections on CPUs 2 and 3, and
# MariaDB with the tables of apps/workload.php must be up.
set -euo pipefail

rounds=$1
shift
here=$(cd "$(dirname "$0")" && pwd)

one() {
    (
        IFS=';'
        for kv in $1; do
            [ -n "$kv" ] && export "${kv?}"
        done
        export CAIRO_PHP_INI="${PHP_INI:-$here/php.ini}" BENCH_THREADS="${BENCH_THREADS:-4}"
        taskset -c "${SERVER_CPUS:-0,1}" setsid "${BIN:-$here/../../cairo/bin/cairo}" serve "$here/server.php" > "$here/server.log" 2>&1 &
        sid=$!
        for _ in $(seq 1 60); do
            curl -sf http://127.0.0.1:8080/hello > /dev/null && break
            sleep 0.25
        done
        load() { taskset -c "${LOAD_CPUS:-2,3}" wrk -t2 -c64 -d"$1" -s "$here/real.lua" http://127.0.0.1:8080; }
        load 2s > /dev/null
        load 8s | awk '/Requests\/sec/ {r=$2} /Non-2xx/ {bad=$NF} END {print r (bad ? " BAD" bad : "")}'
        pkill -TERM -s "$sid" || true
        for _ in $(seq 1 20); do
            pgrep -s "$sid" > /dev/null || break
            sleep 0.25
        done
        pkill -KILL -s "$sid" 2> /dev/null || true
    )
}

n=$#
for i in $(seq 1 "$rounds"); do
    line="round $i"
    for j in $(seq 0 $((n - 1))); do
        k=$(((i + j) % n + 1))
        cfg=${!k}
        label=${cfg%%=*}
        env=${cfg#*=}
        [ "$env" = "$cfg" ] && env=""
        line="$line $label $(one "$env")"
    done
    echo "$line"
done
echo RRDONE
