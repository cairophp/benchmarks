#!/usr/bin/env bash
# What each operation of Cairo's core costs, measured inside one request on an
# otherwise idle server: one line per operation, elapsed and CPU time in ns.
#
#   ./run.sh ../../cairo/bin/cairo             # one PHP thread, on CPUs 0 and 1
#   ./run.sh old/cairo > out/coreops-old-1.txt # runs to compare with compare.py
#
# Run the two binaries in turn, several times each: one run says little.
set -euo pipefail

bin=$1
here=$(cd "$(dirname "$0")" && pwd)
BENCH_THREADS=${2:-1} CAIRO_PHP_INI="$here/../apps/php.ini" \
    taskset -c "${SERVER_CPUS:-0,1}" setsid "$bin" serve "$here/server.php" > "$here/server.log" 2>&1 &
pid=$!
trap 'kill -TERM -- "-$pid" 2>/dev/null || kill -TERM "$pid" 2>/dev/null || true' EXIT
for _ in $(seq 1 60); do
    curl -sf http://127.0.0.1:8080/hello > /dev/null && break
    sleep 0.25
done
curl -sf --max-time 600 http://127.0.0.1:8080/ops
