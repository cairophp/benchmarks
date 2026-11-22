# Performance work log

What was changed to make Cairo faster, why, and what each change measured.
Every number comes from `run.sh` (or a smaller run of the same cases)
on the machine described in [README.md](README.md): an i7-8565U laptop, the
server pinned to two physical cores, PHP 8.5.12 with opcache. Numbers on this
machine move by 5-10 % between runs, so a change is only kept when it shows
up beyond that.

## Results after each step

Cairo, requests per second (calls or streams per second for gRPC), median of
three 10 s runs. The other servers' numbers are in [README.md](README.md).

| Case | v0.1.0 | 1. first fixes | 2. MySQL + GC | 3. binary events |
|---|---:|---:|---:|---:|
| hello | 19,481 | 36,314 | 42,322 | **49,601** |
| json | 19,231 | 35,120 | 40,633 | **45,959** |
| cpu | 3,698 | 3,901 | 3,895 | 4,039 |
| sleep (20 ms) | 11,570 | 11,023 | 11,227 | 11,303 |
| curl (20 ms upstream) | 5,973 | 11,430 | 11,612 | 11,631 |
| `Cairo\Http\Client` | 8,043 | 10,631 | 11,351 | 11,346 |
| PDO PostgreSQL point query | 8,089 | 11,682 | 12,377 | **14,271** |
| PDO PostgreSQL 10 ms query | 3,630 | 3,567 | 3,620 | 3,650 |
| `Cairo\Sql` PostgreSQL | 9,023 | 13,875 | 15,374 | **16,621** |
| PDO MySQL point query | 7,131 | 12,881 | 13,461 | **15,659** |
| PDO MySQL 10 ms query | 3,722 | 3,679 | 3,666 | 3,728 |
| `Cairo\Sql` MySQL | 6,854 | 9,459 | 15,425 | **15,938** |
| gRPC unary | 12,979 | 15,075 | 15,926 | **16,485** |
| gRPC server streaming (100 replies) | 444 | 820 | 886 | **909** |
| gRPC 20 ms handler | 2,753 | 2,745 | 2,778 | 2,795 |

The sleep and 10 ms query cases do not move: they are bound by the wait
itself and by the 40 database connections every server gets (40 / 10 ms is
4,000 req/s at most). In v0.1.0 the PDO cases also returned errors (see 1e).

## 1. First fixes (commit d201bba)

Found by measuring per-thread CPU (`/proc/<pid>/task/*/stat`), context
switches and TLB shootdowns (`/proc/interrupts`) under load, and by
sweeping the thread count: on hello, one PHP thread did 29k req/s and four
did 22k, so something got worse with more threads.

### 1a. A Go scheduler slot for each PHP thread

**What.** `GOMAXPROCS` is set to the CPU count plus the number of PHP
threads, unless `GOMAXPROCS` is in the environment (`internal/sapi/engine.go`).

**Why.** A PHP thread is a C thread. Each time it calls into Go (to answer a
request, to start an I/O wait) it needs a free P, Go's scheduler slot. With
one P per CPU, net/http's goroutines held them all under load and the PHP
threads queued for one. Raising `GOMAXPROCS` from 4 to 8 by hand took hello
from 22.9k to 26.5k and halved its p99.

**Effect.** About +15 % on hello, p99 18 ms to 8-10 ms.

### 1b. Reuse request fibers

**What.** A fiber whose request finished is kept (up to 64 per thread) and
runs the next request or `async()` task (`Scheduler::startFiber()` in
`php/runtime.php`). The per-fiber deadline state in C is reset when the
handler's `cairo_guard()` ends, so a reused fiber starts clean
(`internal/sapi/cairo.c`).

**Why.** Every request created a Fiber: PHP mmaps its stack and munmaps it at
the end, and the munmap sends a TLB-shootdown interrupt to every CPU running
the process. Measured: 2.0 shootdowns per request, and creating a fiber cost
7.2 µs on its own.

**Effect.** Shootdowns per request 2.0 → 0.08. Together with 1c, hello went
from 23k to 34k with `GOMAXPROCS` pinned, before 1a's gain.

### 1c. Typed request events

**What.** The HTTP and gRPC servers built each request as a
`map[string]any` for `json.Marshal`; they now use structs.

**Why.** Encoding the map took 6.0 µs and 39 allocations per request, the
struct 1.4 µs and 8 (a Go benchmark of the same event). Replaced again in 3.

### 1d. curl keep-alive

**What.** The curl hook keeps one multi handle per easy handle, in a
per-thread table, and frees it in a hooked `curl_easy_cleanup()`
(`internal/sapi/curl_hook.c`, `scripts/build-static.sh`).

**Why.** A hooked `curl_exec()` ran the transfer on a new multi handle and
destroyed it after. A multi handle owns its connection cache, so every
`curl_exec()` opened a new TCP connection even on a reused handle: 239 µs
per call to a local server, against 123 µs through `Cairo\Http\Client`.

**Effect.** curl 6.1k → 11.2k req/s. Now ahead of Swoole (9.1k) on this case.

### 1e. `Cairo\Pool` drops connections of cancelled requests

**What.** `Pool::with()` drops the connection when the callback throws or the
request's context ended; a connection held with `get()` is dropped when the
fiber's context ended. `discard()` is documented for connection errors.

**Why.** A bug found while setting up the benchmark. When a client goes away,
Cairo cancels the request's query, which closes the PDO connection mid-reply,
and the pool handed the dead connection to the next request: every later
request on it failed with "server closed the connection" or "MySQL server has
gone away".

**Effect.** No more errors in the PDO cases (v0.1.0 had up to 53 per run).

### 1f. `Cairo\Sql` keeps its connections idle

**What.** `max_idle` defaults to `max_open` (10 when unlimited).

**Why.** It defaulted to 2, Go's default, so under load database/sql closed
almost every connection after use and opened a new one for the next query.

**Effect.** `Cairo\Sql` PostgreSQL at 64 connections: 950 → 11,400 req/s.

### 1g. gRPC stream replies are queued

**What.** `ServerStream::send()` hands the reply to a 64-entry queue that one
goroutine per stream writes (`internal/grpcserver/stream.go`), through a call
that does not suspend the fiber. A full queue falls back to waiting on the
fiber's goroutine. Replies are flushed before the call's status; a failed
write is thrown by the next `send()`.

**Why.** Each `send()` was a full round trip: JSON op, fiber suspend, the
goroutine writes, an event back, the fiber resumes.

**Effect.** Server streaming 480 → 836 streams/s (100 replies each).

### 1h. No stack trace for cancelled requests

**What.** A request cancelled by its client answers 499 without logging.

**Why.** Every client that went away logged a full stack trace at INFO.

## 2. MySQL round trips and Go's GC (commit 0e06390)

### 2a. `Cairo\Sql` MySQL interpolates parameters

**What.** MySQL URLs get `interpolateParams=true` unless they set it.

**Why.** go-sql-driver/mysql ran every parameterised query as a server-side
prepare, execute and close: three round trips, against one for PostgreSQL
(pgx caches its statements). The driver escapes for the connection's charset
and refuses the charsets where that is unsafe.

**Effect.** `Cairo\Sql` MySQL 10.4k → 14.5k in a direct comparison.

### 2b. `GOGC=400` by default

**What.** `debug.SetGCPercent(400)` unless `GOGC` is set.

**Why.** Go's live heap is small (request data lives in PHP), so at the
default of 100 the collector ran every few hundred requests. Measured on
hello: 100 → 43.5k, 200 → 44.0k, 400 → 45.3k, 800 → 45.9k req/s, for 61,
66, 73 and 89 MiB of RSS. 400 is where the gain flattens.

**Effect.** About +10 % on hello for about 12 MiB.

## 3. Binary events and raw bodies (commit d3bacd0)

**What.** Events from Go to PHP (requests and I/O results) are encoded in a
small tagged binary format (`internal/wire`) and decoded by C straight into
PHP arrays (`internal/sapi/wire.c`). Values without a direct encoding still
go as JSON and are decoded by PHP's json extension. Request and response
bodies cross as raw bytes: `cairo_respond()` takes the body as its own
argument. PHP → Go metadata (status, headers, op payloads) stays JSON: it is
small and Go decodes it into typed structs.

**Why.** Each request was JSON text that PHP decoded with `json_decode()`,
with the body base64'd inside it, and the response came back the same way.
The other servers all pass bodies raw: FrankenPHP reads PHP's output through
SAPI callbacks, RoadRunner puts the body after the JSON header in its frame,
FastCGI has raw STDIN/STDOUT records, and Swoole never leaves C.

**Options weighed.** msgpack needs an extra PHP extension and a
reflection-based Go encoder, for no speed over this. Building PHP values from
Go-owned C structs, as FrankenPHP does, needs C per event type. Keeping JSON
and moving only bodies out gets about half the gain.

**Safety.** The decoder checks every length against what is left of the
buffer and limits nesting to 64; a Go mirror of it is fuzzed
(`internal/wire`). Testing found that PHP's JSON scanner reads until a NUL
byte, so JSON values get their own terminated copy instead of a slice of the
buffer.

**Effect.** Encoding a request: 141 ns and no allocations, against 1.4 µs for
`encoding/json`. hello 46.1k → 50.9k and a 64 KiB echo 938 → 3,638 req/s
side by side; in the full run, hello 42.3k → 49.6k and the PostgreSQL and
MySQL point queries +15 %.

## 4. A working profiler, and responses without JSON (commits 6e53913, 0b8bb7e)

From here each step is measured with `ab.sh`, which runs the previous
build and the new one in turns on the same machine state (three rounds per
case, median). By this point the laptop ran about 15 % slower than in the
morning (the `cpu` case, which no change touches, went from 4.0k to 3.4k),
so absolute numbers from different hours no longer compare; side-by-side
runs do.

### 4a. Go's CPU profiler no longer crashes Cairo

**What.** PHP's signal handlers get `SA_ONSTACK` after PHP starts, and PHP
threads block `SIGPROF` (`internal/sapi/cairo.c`).

**Why.** Starting Go's CPU profiler aborted the process: "non-Go code set up
signal handler without SA_ONSTACK flag". PHP's signal layer (zend_signal)
installs handlers without that flag and passes signals it does not use on to
Go's handler, which must run on the alternate signal stack. Cairo does not
use `SIGPROF` itself (`max_execution_time` is 0; it stops PHP at deadlines on
its own). With the profiler working, the rest of this log is driven by
profiles instead of estimates.

### 4b. HTTP responses reach Go without JSON

**What.** `cairo_respond_http()` takes the status, the headers array and the
body; C writes the status and headers in `internal/wire`'s format, and
`wire.DecodeResponse` reads them into an `http.Header`, checking every length
(fuzzed).

**Why.** The first profile of hello showed 7.6 % of all CPU in Go's
`encoding/json` decoding PHP's response metadata, after PHP had normalised
the headers and `json_encode()`d them.

**Effect** (`ab.sh`, build d3bacd0 against 0b8bb7e):

| Case | Before | After | Change |
|---|---:|---:|---:|
| hello | 42,997 | 44,638 | +3.8 % |
| json | 38,711 | 42,929 | +10.9 % |
| PDO PostgreSQL point query | 11,079 | 11,398 | +2.9 % |
| PDO MySQL point query | 12,591 | 12,980 | +3.1 % |
| curl | 10,884 | 10,987 | +0.9 % |
| `Cairo\Sql` MySQL | 12,845 | 13,213 | +2.9 % |

### What the profile shows now (hello, 4 PHP threads)

| Where | Share of CPU |
|---|---:|
| net/http, of which the response `write` syscall is 18 % and the request `read` 5 % | 55 % |
| PHP threads (the PHP VM, `execute_ex` alone 7 %) | 30 % |
| Cairo's Go code in the request path (`Dispatch`, the route handler) | 14 % |
| Go's allocator / scheduler | 7 % / 5 % |

## 5. Cheaper socket waits (commits 39e255c, 55b25d8, 56e477a)

Measured on a different machine from steps 1-4: a cloud VM with 4 vCPUs
(Xeon @ 2.1 GHz, KVM, no SMT), Cairo built natively against PHP 8.5.12, the
server on CPUs 0-1 and wrk on 2-3. Its absolute numbers do not compare with
the laptop's; the A/B runs below compare builds on the same machine.

### Where the time went (cloud VM, before step 5)

Go's CPU profiler and `perf`, 8 s under wrk:

| Case | CPU per request | What dominates |
|---|---:|---|
| hello | 51 µs | kernel 37 % (thread wakeups, the response `write`), PHP 16 %, net/http's `write` 20 % of Go's samples |
| MySQL point query | 132 µs | PHP + mysqlnd 48 %; each socket wait costs a cgo callback, `runOp`, the poller (5 %) and `encoding/json` (4 %) |
| curl | 182 µs | CPU-bound at 10.5k req/s; libcurl 10 % |

"Try the socket read in C first" was already done: `cairo_wait_fd()` polls the
descriptor before suspending. So this step makes the wait itself cheaper.

### 5a. Socket waits reach Go without JSON

**What.** `cairo_fiber_wait()` and `cairo_fiber_waitset()` (new
`internal/sapi/waitops.c`) pass the descriptor, direction and timeout as
arguments; `Hooks::wait()` and `Hooks::waitSet()` use them.

**Why.** Every hooked read or write that has to wait went through
`json_encode()` in PHP and `json.Unmarshal` in Go for four fields.

**Effect.** MySQL point query +10.8 % (3 rounds); hello and curl unchanged.

### 5b. The poller keeps a socket in epoll between waits

**What.** `poller.wait()` re-arms a descriptor with one `EPOLL_CTL_MOD`
(`ADD` the first time) and leaves it in the set, disarmed by `EPOLLONESHOT`,
instead of `ADD` then `DEL` around every wait. A wait that times out or is
cancelled still deletes it, and the kernel drops a closed descriptor from the
set.

**Why.** Two `epoll_ctl` syscalls per wait, on every MySQL round trip.

**Effect.** MySQL point query +2.6 % (3 rounds), +5.1 % (6 rounds).

### 5c. Payload ops skip the op's JSON wrapper

**What.** `cairo_fiber_call($fiber, $op, $payload)` for `Go::call()`, PDO
pgsql and `offload()`.

**Why.** The payload was JSON, then encoded again as a string inside the op's
JSON (escaping every quote), then unescaped by Go before the op decoded it.

**Effect.** About +2 % on PDO PostgreSQL and `Cairo\Sql`, inside this
machine's noise; kept because it is less work per call and less code path.

### Step 5 together

`ab.sh`, 80ed102 against 5a-5c, 7 rounds, median:

| Case | Before | After | Change |
|---|---:|---:|---:|
| PDO MySQL point query | 16,181 | 17,693 | +9.3 % |
| PDO PostgreSQL point query | 13,524 | 13,829 | +2.3 % |
| `Cairo\Sql` MySQL | 17,486 | 17,794 | +1.8 % |
| json (untouched, as control) | 38,185 | 39,040 | +2.2 % |

The other cases (hello, cpu, sleep, curl, client, the 10 ms queries, gRPC)
did not move beyond ±5 % in a 3-round run of all of them.

## 6. Laravel: waits on the PHP thread, and connections kept across curl handles

Found with the Laravel benchmark (a dashboard endpoint: two Eloquent queries
on MariaDB and two `Http::get()` calls of 20 ms, 40 PHP threads in isolated
mode on 2 vCPUs of the cloud VM). `perf` on that endpoint at 64 connections:

| Where | Share of CPU |
|---|---:|
| libphp (Laravel's own PHP code; `execute_ex` alone 21 %) | 64 % |
| Kernel, about half of it thread switches (`finish_task_switch`, the run-queue lock) | 18 % |
| Cairo's Go side (scheduler, cgo callbacks) | 9 % |
| libc and libcurl | 8 % |

Two thirds is the application, the same on every server. What Cairo can
change is the cost of each wait and what the waits are for: `connect`,
`close`, `socket` and `pipe2` showed up because every `Http::get()` opened a
new connection.

### 6a. Short socket waits happen on the PHP thread

**What.** Before a hooked socket wait goes to Go, the thread polls the socket
itself for up to 1 ms (`cairo_wait_here()` in `internal/sapi/waitops.c`,
`Scheduler::waitHere()`), but only when nothing else could run on the thread
meanwhile: no fiber is ready or waiting for Go, and Go has no event queued for
the thread (isolated mode skips that last check, since a queued request waits
for the running one anyway).

**Why.** A wait through Go is a cgo call, an `epoll_ctl`, a goroutine wake and
an event back: about four thread switches. A database on the same network
answers a point query in 100-300 µs, so one `poll()` on the thread usually
gets the reply directly. When other fibers have work, the thread must not
block, so the wait goes to Go as before.

**Options.** (1) This. (2) The same without the queue check: faster at 1-4
connections (+40 % and +22 %) but 29 % slower at 64, because the thread held
still while requests queued. (3) An eventfd that interrupts the poll when Go
queues an event: no 1 ms cap needed, but a second wake path to maintain; not
needed for these numbers.

**Effect.** PDO MySQL point query in fiber mode, 2 threads (req/s, median of 3):

| Connections | Before | After | Change |
|---:|---:|---:|---:|
| 1 | 2,586 | 3,193 | +23 % |
| 4 | 7,811 | 8,329 | +7 % |
| 16 | 12,732 | 13,704 | +5 % (within noise) |
| 64 | 15,424 | 15,414 | 0 % |

Laravel dashboard, 64 connections, 5 alternating rounds: 376 → 386 req/s
(+2.6 %, within noise: at saturation the database's replies are slower than
the poll and Laravel dominates).

### 6b. A new curl handle takes over a closed handle's connections

**What.** When a hooked easy handle is closed, its multi handle (which owns
the connections) is kept, up to 4 per thread, and given to the thread's next
new easy handle (`spare_multis` in `internal/sapi/curl_hook.c`). They are
closed when the thread ends.

**Why.** Step 1d kept connections alive per easy handle. Laravel's `Http`
facade builds a new Guzzle client, and so a new curl handle, for every call,
as does any code that calls `curl_init()` per request: each call paid a TCP
connect (and for HTTPS a TLS handshake), a close and a new multi handle.
libcurl only reuses a connection whose host, TLS settings and credentials
match, so handing the cache to another handle is what a shared Guzzle client
does already.

**Options.** (1) This: 30 lines, no change to the handles themselves. (2) One
multi handle per thread for all transfers: also shares connections between
transfers that run at the same time, but fibers would complete each other's
transfers and consume each other's messages. (3) A curl share handle with
`CURL_LOCK_DATA_CONNECT` set on every easy handle: would override a share the
application set.

**Effect.** Laravel, 64 connections, 4 alternating rounds, against 6a alone:

| Case | Before | After | Change |
|---|---:|---:|---:|
| dashboard | 388 (p50 186 ms) | 434 (p50 164 ms) | +12 % |
| dashboard with `async()` | 396 (p50 172 ms) | 440 (p50 155 ms) | +11 % |

At 4 connections p50 went from 52.9 to 50.5 ms (sequential) and from 26.2 to
24.5 ms (`async()`), 6a and 6b together.

### Step 6 together

`ab.sh`, 5 rounds, the build before step 6 against 6a + 6b: hello
-0.4 %, PDO MySQL +0.2 %, curl -0.5 %, `Cairo\Sql` MySQL +0.2 % (all noise:
these cases run many fibers per thread and keep their curl handles), PDO
PostgreSQL +7.8 % (not touched by either change; noise).

## 7. Response cache in Go (`->cache()`)

Roadmap idea 2: a route that opts in with `->cache(ttl, staleWhileRevalidate,
vary)` is answered by Go from a bounded in-memory LRU (64 MiB per server, 1
MiB per response), identical requests in flight wait for one PHP call, and a
stale entry is served while one background request rebuilds it. Only GET and
200 responses without `Set-Cookie` or private `Cache-Control` are stored; a
request with `Authorization` or `Cookie` skips it unless the route varies on
that header.

**Options.** (1) Our own map + `container/list` LRU with a flight per key,
chosen. (2) `golang.org/x/sync/singleflight` for merging: it hands the
leader's answer to every waiter, including an answer that may not be shared
(`Set-Cookie`), so waiters would need a second check anyway; 30 lines of our
own are simpler. (3) A sharded cache: not needed, a hit holds the lock for a
map lookup and a list move, and the cached cases below run at 80-96k req/s.

**Effect on uncached routes.** The route handler was split into
`newEvent`, `callPHP` and `write`; `callPHP` returns its response by value so
the uncached path allocates what it did before (13 allocations,
`BenchmarkUncachedRoute`). `ab.sh`, 5 rounds in each order, the build
before against this one: hello -0.1 % and +2.1 %, json +1.1 % and +4.9 %
(noise). An earlier draft that allocated the response and a closure per
request measured -6.5 to -8.9 % on hello and was changed.

**Effect on cached routes.** `run.sh cairo`, 3 runs of 10 s:

| Case | Without cache | With `->cache()` | Change |
|---|---:|---:|---:|
| cpu (`fib(20)`, 64 connections) | 3,510 (p99 25.9 ms) | 82,560 (p99 9.0 ms) | 23x |
| PDO MySQL 10 ms query (256 connections, ttl 1 s, stale 10 s) | 3,716 (p99 83.6 ms) | 79,229 (p99 22.2 ms) | 21x |
| hello, uncached, same run | 46,428 | | |

A hit costs Go's HTTP server and a map lookup, so cached routes run above
uncached hello (no PHP at all) and below plain Go net/http (99k on hello).
In the MySQL case one background request rebuilds the entry about once a
second (the TTL); every other request gets the cached or stale copy.

### 7b. Purge by tag, and HEAD from the cache

`->cache(tags: [...])` and `Cairo\Cache::purge('tag')`: each tag has a
generation counter, an entry remembers the sum of its route's generations,
and a lookup treats an entry from an older generation as gone. HEAD reads
what a GET stored and never fills the cache.

**Options.** (1) A generation per tag, chosen: a purge is one atomic add per
tag, whatever the cache holds, and stale entries leave on their next lookup
or through the LRU. (2) Walking the cache on purge and deleting tagged
entries: holds the cache lock for up to ~250k entries while hits wait. (3)
An index from tag to entries: more memory and bookkeeping on every store for
the same result.

**Effect.** A hit on an untagged route does no extra work; a tagged route
reads one atomic per tag. `ab.sh`, 5 rounds, step 7 against this build:
hello +3.9 %, json +2.6 %, cpu-cached +0.7 % (83,503 and 84,106): all noise.

## 8. Connection pools done well (idea 3, pooling only)

Not a speed-up: `Cairo\Pool` gets a bounded wait (`acquireTimeout`), a reset
before a connection is reused (a PDO transaction left open is rolled back),
and `maxLifetime` / `maxIdleTime`. The work here was keeping the point-query
cases, where every request goes through the pool, from getting slower.

| Version | PDO MySQL point query | PDO PostgreSQL point query |
|---|---:|---:|
| Every waiter parks through Go, and the reset asks `inTransaction()` | -14.9 % | -12.6 % |
| Waiters wait in PHP unless `acquireTimeout` is set | -9.4 %, -2.8 % | -6.0 %, -6.4 % |
| The same, and pdo_pgsql keeps the transaction status from each reply | -3.1 %, -2.7 %, +1.5 %, +3.7 % | -2.1 %, +14.6 %, -2.5 %, -5.2 % |

`ab.sh`, 4 to 6 rounds, v0.2.0 against each version; several figures are
runs in both orders.

**Why.** (1) Waiting for a connection through a Go "park" costs two thread
crossings, and at 64 connections on 40 database connections most requests
wait once. The pool now waits in PHP alone, as before, unless
`acquireTimeout` asks for a timer; a request past its deadline is skipped
when a connection comes back. (2) `PDO::inTransaction()` on Cairo's pgsql
driver called Go and decoded JSON each time. Every reply now carries the
transaction status and the driver keeps it.

**Settled on a quiet host** (cloud VM, 4 vCPU, 2026-11-18; native PHP
8.5.12 ZTS builds of v0.2.0 and of this branch; MariaDB 11.4 and
PostgreSQL 16 on the same VM). `ab.sh` favours whichever binary runs first
in a round, so every comparison ran in both orders, and a control ran the
same binary against itself:

| Run | PDO MySQL point query | PDO PostgreSQL point query |
|---|---:|---:|
| Control: this branch against itself, 10 rounds | -2.1 % | -2.9 % |
| 6 rounds, both orders | +0.2 %, +2.2 % | -6.6 %, +8.8 % |
| 10 rounds, both orders | -3.6 %, +4.8 % | -7.0 %, -1.0 % |
| 10 rounds, both orders, databases pinned to their own CPU | -1.1 %, -2.3 % | +8.1 %, -4.6 % |

Averaged over both orders that is +0.8 %, +0.5 % and -1.7 % on MySQL and
+0.8 %, -4.0 % and +1.6 % on PostgreSQL: no change beyond the 2 to 3 % the
control shows. The slow-query cases moved by less than 1 % and hello, which
does not touch the pool, by -0.5 %.

**What `acquireTimeout` costs.** The same branch with `acquireTimeout: 5`
on both pools, against none, in both orders: MySQL point query -7.6 % and
-6.4 %, PostgreSQL -8.4 % and -3.9 %, the slow cases -0.7 % to -1.4 %. At 64
connections on 40 database connections most requests wait once, and with a
timeout that wait parks through Go. It is off by default, and the docs say
it costs a little on a pool that is always full.

## 9. One PostgreSQL pool for all threads (`cairo_pool=N` in a pgsql DSN)

PDO objects made with `cairo_pool=N` in their DSN share N server connections
held in Go, across all PHP threads. Each one borrows a connection for the
request that uses it. Before this, each thread's `Cairo\Pool` owned its own
connections, so a busy thread could wait while another thread had idle ones.

**Mixed load** (4 threads, 256 connections on `/sleep`, which uses no
database, at the same time as 64 on `/pgsql-slow`; 3 rounds, cloud VM):

| Database connections | Setup | `/pgsql-slow` req/s | p99 |
|---|---|---:|---:|
| 8 | Per-thread pools, `max: 2` | 723 | 223 ms |
| 8 | `cairo_pool=8` | 743 (+3 %) | 107 ms (-52 %) |
| 40 | Per-thread pools, `max: 10` | 3,430 | 44 ms |
| 40 | `cairo_pool=40` | 3,649 (+6 %) | 31 ms (-30 %) |

**Even load** (`ab.sh`, the `pgsql` point-query case, both orders): -2.4 %,
within the 2 to 3 % an A/A control shows. That is the cost of borrowing and
returning a connection in Go once per request. `pgsql-slow`: +0.2 %.

**Why.** Cairo sends each request to the least busy thread by requests in
flight, not by database use, so the requests that need a connection are
spread unevenly at any moment. With one pool, a waiting request gets
whichever connection is free first, on any thread.

## 10. `Cairo\Sql` hardening: no transaction left on a shared connection

A correctness fix with a cost check, not a speed-up. A plain `BEGIN` on a
`Cairo\Sql` pool left its transaction on the connection, and the next request
to get it, on any thread, ran inside it (seen on PostgreSQL and MariaDB).
Now `BEGIN`, `START TRANSACTION`, `SET autocommit`, `LOCK` and `XA` on the
pool are refused, a PostgreSQL connection that still comes back in a
transaction is closed, and `acquire_timeout` bounds the wait for a
connection (off by default).

**Cost.** The statement check takes 28 ns and allocates nothing
(`go test -bench`), against about 50 µs per request. `ab.sh`, 9 rounds,
both orders, against v0.3.0:

| Case | base first | new first | A/A control |
|---|---:|---:|---:|
| `sql-pgsql` | -3.4 % | -2.2 % | -5.5 % |
| `sql-mysql` | +2.7 % | +2.1 % | -7.4 % |

The A/A control on the same VM moved 5 to 7 % that day, so both cases are
within noise.

## 11. Dispatch passes over a thread stuck in one fiber (idea 6, step 1)

Go sent each request to the thread with the fewest requests in flight. A
thread running one 300 ms CPU loop has one request in flight, so it looked
idle and got new requests, which then waited for the loop. Now a thread
whose PHP has run 5 ms without coming back for an event is passed over
while another thread is not stuck.

**Arrivals during a long run** (`TestFairDispatch`: 2 threads, a 300 ms CPU
loop, 40 light requests from 4 clients meanwhile, 3 runs):

| | median | slowest |
|---|---:|---:|
| before | 6 ms | 256 ms |
| after | 6 ms | 6 to 7 ms |

**Steady mixed load** (1 connection on the new `/heavy` route, 40 ms of
CPU that never yields, plus 64 on `/sleep`; 2 threads; 4 rounds each,
alternating): `/sleep` p99 is 94 to 110 ms before and 96 to 115 ms after,
no change. Under steady load the heavy request starts on a thread that
already holds about half of the sleeping light requests, and their wake-ups
wait for it. Step 1 only helps requests that arrive while a thread is stuck.
The fibers already parked there need time slices (step 3) or `offload()`.

**Cost:** `ab.sh` hello -1.2 % and +0.9 %, json +0.4 % and -3.8 %, both
orders: within noise. One clock read per dispatch.

## 12. What the slow request log costs (`$app->slowRequests()`)

Not a speed-up: a check that the new log costs nothing. Go times every wait
of a request (one nil check per wait when the log is off, two clock reads
when it is on). `BENCH_SLOW_REQUESTS=10` turns it on with a threshold no
request reaches, so the cost is measured without any logging.

`ab.sh`, 5 rounds, both orders (an A/A control shows 5 to 7 % today):

| Comparison | hello | pgsql |
|---|---:|---:|
| v0.3.0 → log built in, off | -0.7 %, +1.7 % | +6.3 %, +0.2 % |
| off → on | +0.8 %, -1.8 % | +5.4 %, +0.8 % |

(The second number of each pair is the reverse run, sign flipped so both
read old → new.) All within noise.

## 13. What the repeated query detector costs (`$app->repeatedQueries()`)

Not a speed-up: a check that the N+1 detector is cheap enough to leave on.
With it on, PDO and `Cairo\Sql` report every statement to PHP, which works
out its shape (literals replaced by `?`) and counts it for the request.
`BENCH_REPEATED_QUERIES=1000` turns it on with a threshold no route
reaches, so nothing is logged.

`ab.sh`, both orders (old → new; an A/A control shows 5 to 7 % today):

| Comparison | Rounds | hello | pgsql | mysql |
|---|---:|---:|---:|---:|
| main → detector built in, off | 5 | -1.1 %, +0.8 % | -0.4 %, +1.4 % | -0.4 %, +2.9 % |
| off → on, first version | 5 | -1.0 %, +0.1 % | -4.9 %, -5.5 % | +2.8 %, -0.5 % |
| off → on, first version | 9 | | -3.2 %, -7.8 % | |
| off → on, shapes remembered | 9 | | +1.0 %, -0.2 % | |

The first version was about 5 % slower on pgsql in all four runs. Most of
that was four `preg_replace` patterns per statement (0.8 µs each time in a
microbenchmark). A statement usually runs many times, so each thread now
remembers the shapes of 512 statements of up to 1 KB, and a lookup costs
0.01 µs. With that cache the cost is within noise.

## 14. What the heavy-route warning costs (`$app->heavyRoutes()`)

Not a speed-up: a check that the warning costs nothing. It reuses the slow
log's timing (step 12): with it on, Go times each request's waits, and when
the request ends it reads the longest stretch of PHP and warns about the
route past the threshold. `BENCH_HEAVY_ROUTES=10` turns it on with a
threshold no route reaches, so nothing is logged.

`ab.sh`, 5 rounds, both orders (an A/A control shows 5 to 7 % today):

| Comparison | hello | pgsql |
|---|---:|---:|
| main → warning built in, off | -2.1 %, +1.4 % | -2.6 %, -3.1 % |
| off → on | -0.6 %, -0.9 % | -3.9 %, +0.2 % |

(The second number of each pair is the reverse run, sign flipped so both
read old → new.) All within noise.

## 15. What request-local statics cost (`Cairo\RequestLocal`)

Not a speed-up: a check that the swap of registered statics (idea 1, step 1)
costs plain apps nothing and Laravel-sized lists little.

- **Plain apps, nothing registered.** Each fiber start and request end now
  calls into C, and every fiber switch runs one more observer, which returns
  after one comparison. `ab.sh`, pgsql, 7 rounds, PostgreSQL and MariaDB
  pinned to CPU 3:

  | Comparison | pgsql |
  |---|---:|
  | main → main (A/A) | -0.4 % |
  | main → this | +3.5 %, +0.8 %, +0.1 % |

  (The middle number is the reverse run, sign flipped.) Within noise. A
  first run without the databases pinned showed -9 % and -5.6 %, and an
  A/A run would have shown the same: the databases shared CPUs with the
  load generator.
- **The swap itself.** A route ping-pongs two fibers of two different
  requests, 1,000,000 rounds, 7 runs, pinned to one CPU. The median fiber
  switch went from about 60 ns with nothing registered to about 75 ns with
  three statics: about 5 ns per static, far under the 1 µs budget.
## 16. Isolated mode: one FIFO queue for free threads

**Problem.** In isolated mode each thread runs one request at a time, but
dispatch sent every request to a thread at once (the least busy one), where
it waited in that thread's queue. A request that landed behind a slow one
waited for it even when another thread was free. Laravel through Octane at
256 connections had p99 1.8 to 3.5 s on Cairo, against 0.6 s on FrankenPHP,
which keeps one queue and hands a request only to a free thread.

**Change.** In isolated mode requests wait in Go, on a channel of free
thread slots (Go hands values to waiting receivers in order, so the queue is
first in, first out), and a thread gets its next request once it has
answered. Fiber mode is unchanged.

**Effect.** Laravel 13 + Octane on Cairo, 40 threads on 2 vCPUs, wrk on 1,
MariaDB and the upstream on 1; 3 × 10 s per case, two rounds in opposite
orders (medians):

| Case | main | FIFO |
|---|---|---|
| dashboard, 256 conn | 466 req/s, p99 1.8-2.1 s (max 6.3 s) | 498 req/s (+7 %), p99 0.59 s (max 0.74 s) |
| dashboard with `Octane::concurrently`, 256 conn | 467 req/s, p99 2.1 s | 471 req/s, p99 0.67 s |
| dashboard, 16 conn | 322 req/s | 315 req/s (-2 %, within noise) |
| hello, 64 conn | 2,040 req/s | 1,880 req/s (-8 %) |

Hello loses 8 %: with nothing queued, a thread waits while its answer goes
back through Go and the next request comes in. Letting each thread hold one
more request gave that back (2,118 req/s) but put that request behind a slow
one again (a test with one 2 s request and six of 50 ms on two threads: the
slowest short one took 1.95 s instead of 0.3 s), so it was not kept.

## 17. Laravel in fiber mode under slow dependencies (idea 1)

**Problem.** Every PHP server runs one Laravel request per worker, so when a
dependency slows down, throughput falls to workers ÷ request time: 40
workers and a request that waits 2 s serve 20 requests per second, with the
CPU idle.

**Change.** Idea 1, finished: `Cairo\RequestLocal` (step 15), the Octane
driver's fiber mode (cairophp/octane, one booted app per request in flight,
and every static an app sets while booting kept per app), and
`App::detectLeaks()` (cairo#25) to find statics that hold request state.
No change to the request path of plain Cairo apps.

**Effect.** Laravel 13 + Octane, the dashboard (one DB query, two upstream
calls) at 256 connections, measured in the cloud container (2 vCPUs for the
server, not the laptop above); req/s, median of 3 × 10 s:

| Upstream | FrankenPHP 40 workers | Cairo isolated 40 threads | Cairo fibers 4×10 | Cairo fibers 4×64 |
|---|---|---|---|---|
| 20 ms | 453 | 510 | 503 | 347 |
| 200 ms | 94 | 95 | 93 | **334** |
| 1,000 ms | 16 | 16 | 16 | **102** |
| 200 ms, calls in `Octane::concurrently()` | 92 | 188 | 188 | **335** |
| 1,000 ms, calls in `Octane::concurrently()` | 16 | 36 | 36 | **198** |

Memory: FrankenPHP 290 MiB, isolated 300-330, fibers 4×10 200-210, fibers
4×64 420-510 (256 booted apps). At 1 s the 4×64 setting serves 6.4 times
more than 40 workers. At 20 ms the CPU is the limit and 4×64 loses 30 %
(twice the p99) to switching between 256 apps and their GC, so
`--concurrency` should follow how slow the app's dependencies are.

Registering the 40 statics that a Laravel 13 app sets while booting (instead
of the fixed list of 16) costs nothing measurable: hello at 64 connections,
fibers 4×10, 1,994 and 2,127 req/s before, 1,913 and 2,133 after.

## 18. What the leak detector costs (`$app->detectLeaks()`)

Off by default, and then one bool check per request. On, it reads every
static of the app's classes when each request ends: Laravel hello at 2
threads goes from 2,018 to 521 req/s, about 3 ms of CPU per request. It is a
development aid; keep it off in production.

## 19. The core engine round: waits on the PHP thread, queues to Go, the scheduler in C

One pull request, [cairophp/cairo#36](https://github.com/cairophp/cairo/pull/36),
with a commit for each step. Its description has the options that were
compared and the tests of each; this is the record of what each step measured.

Until here every fiber had a goroutine and a context in Go, every wait (a
sleep, a channel, a socket) crossed to Go and back, and a PHP thread called
into Go for its next event and to hand over an answer. Each of those calls
held one of Go's processors, which Go's scheduler then took back and gave out
again. Now a fiber is PHP alone, a wait that needs nothing of Go stays on its
thread, Go and the threads pass requests and answers through queues that
neither side calls the other for, and a goroutine runs only for the length of
a Go operation.

### How it was measured

The cloud VM of step 5, the server on 2 CPUs with 4 PHP threads and wrk at 64
connections on the other two. Every commit's binary ran in turn with the
binary of the commit before it: 40 alternating rounds of 4 s for each route
(80 or 120 where the difference was near the error), and 6 to 12 runs of the
core operations (`coreops/`, below) on one PHP thread. A figure after "±" is
one standard error. Three rounds, as `ab.sh` does by default, cannot see a
change of 2% on this machine: run it with `ROUNDS=40`.

`hello` here is the plain-text route, `/cpu` computes for 350 µs in PHP, and
`/pgsql` and `/mysql` run one PDO query on a random row.

### The whole series

`main` before the pull request (6f35a4d) against its last commit (81eaaae):

| Case | Before | After | Difference |
|---|---:|---:|---|
| hello, requests per second | 38,221 | **51,619** | +35.1% ± 1.6%; CPU per request 51.5 to 37.7 µs |
| `/mysql` | 15,851 | **27,489** | +73.4% ± 2.2%; CPU per request 113.8 to 65.7 µs |
| `/cpu` | 3,491 | **4,029** | +15.4% ± 1.6% |
| `/pgsql` | 15,952 | 16,576 | +3.9% ± 1.6% |
| `Cairo\sleep(0)` | 37,531 ns | **761 ns** | -98.0% |
| A channel round trip between two fibers | 79,344 ns | **3,935 ns** | -95.0% |
| `withTimeout()` + `cancel()` | 4,615 ns | **347 ns** | -92.5% |
| `Channel` send + receive, nobody waits | 1,262 ns | **100 ns** | -92.1% |
| `async()` + `await()` | 6,057 ns | **1,194 ns** | -80.3% |
| `Context::current()` | 120 ns | **28 ns** | -76.6% |
| `Go::callNow` (a Go operation on the PHP thread) | 2,725 ns | **931 ns** | -65.8% |
| `offload()`, an empty job | 90,566 ns | **34,957 ns** | -61.4% |
| `Go::call`, 64 fibers at once (per call) | 3,959 ns | **2,281 ns** | -42.4% |
| `Go::call`, one fiber on an idle thread | 38,341 ns | 31,268 ns | -18.4% ± 1.9% |

### Each step

In the order they were made, which is the order of their dependencies. The
numbers of a step are against the commit before it.

| Step | What changed | What it measured |
|---|---|---|
| 19a (916465b) | Cairo's C code is compiled at `-O2`. The build set `CGO_CFLAGS` to PHP's include paths, which dropped the optimization Go asks for by default | `Go::call` ×64 -8.3%, `sleep(0)` ×64 -7.8%, `/pgsql` +3.6% ± 1.6%, hello +1.1% ± 1.5% |
| 19b (1ab5d2c) | Opcache holds Cairo's own PHP files. They are written at start, and opcache leaves out a file that changed in the last two seconds, so every thread compiled the runtime itself | `Context::current()` 121 to 63 ns, `async()` + `await()` -26.6%, hello +2.5% ± 1.1% |
| 19c (98275ea) | On glibc, a call from a fiber's stack into Go no longer makes Go fetch the thread's stack from the system (a `sched_getaffinity` call and an allocation each time) | `withTimeout()` + `cancel()` -27.4%, `async()` + `await()` -20.6%, `Go::callNow` -14.2%, hello +0.9% ± 0.9% |
| 19d (1e08160) | The calls a thread took and its fibers are in tables of that thread, not in two maps locked by all threads | hello +3.1% ± 1.0% |
| 19e (151bc9c) | A request's objects in Go are reused: eight allocations and 1 kB less per request | hello +2.4% ± 0.8% |
| 19f (db3ad52) | A request's fibers wait under the server's own context, not one made for each request: five allocations less | hello +4.4% ± 0.9% |
| 19g (b3c576d) | A request goes to a thread that is running PHP and will take it within 50 µs, when there is one, instead of waking a thread that sleeps | hello +6.1% ± 1.0%, `/pgsql` -0.2% ± 1.0% |
| 19h (0d9d674) | The arguments of a Go operation cross in the binary format of step 3, not as JSON | `Go::callNow` 2,268 to 910 ns, `Go::call` ×64 -9.9%, hello -0.2% ± 0.8% |
| 19i (f7ae72c, 03ddda8) | `sleep()`, channels, `select()` and `Cairo\Pool` wait on the PHP thread, which keeps the timers. Go and the thread share a mailbox, and a thread that gave Go an operation watches it for a few microseconds before it sleeps | `sleep(0)` 32,869 to 1,313 ns, channel round trip 62,518 to 5,597 ns, `Go::call` of one fiber -29.8%, hello 0.0% ± 1.3% |
| 19j (14f5a76) | Requests and answers cross in lock-free queues. A thread sleeps and wakes in C (a futex), and one goroutine collects the answers of all threads | hello +8.0% ± 1.8% (CPU per request -9.5%), `/cpu` +9.6% ± 0.9%, `/mysql` +8.0% ± 2.2%, `/pgsql` -3.0% ± 1.4%, `Go::call` of one fiber +23.4%, `offload()` +38.9% (fixed in 19m) |
| 19k (f4f0178) | A fiber has no goroutine and no context in Go. `async()` and the derived contexts are PHP alone, and an operation's goroutine then waits for the thread's next operation | `async()` + `await()` 3,593 to 1,421 ns, `withTimeout()` + `cancel()` 2,895 to 360 ns, hello +2.0% ± 0.8%, `/mysql` +1.8% ± 1.2% |
| 19l (cd44990) | Socket waits (PDO MySQL, streams, curl) on an epoll of each PHP thread, where a thread with nothing to run sleeps and Go wakes it through an eventfd | `/mysql` +19.8% ± 1.7% (CPU per request 88.3 to 69.4 µs), curl CPU per request -18.0% ± 2.7%, hello -0.5% ± 0.8% |
| 19m (a3b2e7f) | An `offload()` job goes from its request thread straight into the inbox of an offload thread and its result straight back: two wake-ups of threads, where it took four | An empty job 99,987 to 35,585 ns; a job of 50 µs costs 87 µs of CPU, where it cost 165 |
| 19n (a3fd582) | `Channel::send()` with room and `recv()` of a buffered value no longer go through `select()` | Send + receive 1,170 to 102 ns, round trip between two fibers -22.0% |
| 19o (81eaaae) | The scheduler's hot path in C: the context of each fiber, the idle fibers, the ready queue, and the parked waits with their timers | `sleep(0)` ×64 1,379 to 675 ns, `Context::current()` 69 to 33 ns, `/mysql` +15.8% ± 1.5% (a PDO query parks its fiber three times), hello +2.7% ± 1.0% |

### What stays open

- `/pgsql` lost 3.0% in 19j and is +3.9% over the whole series. A PDO pgsql
  query is a Go operation, so each one still takes a goroutine and two
  wake-ups; `/mysql`, which waits on its own socket, no longer does.
- `Go::call` from one fiber on a thread with nothing else to do takes about
  31 µs: the thread sleeps, and waking it costs most of that. From 64 fibers
  at once it is 2.3 µs a call.

### The machine, and the benchmark of the core operations

The smallest operations ran at one of two speeds from run to run of the same
binary, and a route seemed to lose 1.7% on code that had not changed. Both
were the machine. The cloud VM runs everything 1.3 to 2.1 times slower for
0.1 to 0.7 s at a time, at no fixed moment: a C program with no PHP and no Go,
alone on a CPU, shows it too, and a busy neighbour CPU makes no difference.
Code that calls and touches memory loses more in such a stretch than a bare
loop. Where code and data sit in memory moves these operations by about 1%,
across starts and across builds.

So the core operations are measured in short pieces. `coreops/server.php`
runs each operation in 25 pieces, in turn with the pieces of the others, and
its fastest piece counts; five long rounds in a row could all fall inside one
slow stretch. Six runs then agree to under 1% (`Context::current()` 60.5 ±
0.2 against 28.0 ± 0.0 ns for 19n and 19o), where twelve runs of the old way
left a standard error of up to 7%.

```sh
coreops/run.sh old/cairo > out/coreops-old-1.txt   # in turn, several times each
coreops/run.sh new/cairo > out/coreops-new-1.txt
coreops/compare.py out old new
```

For the routes, a slow stretch is shorter than a round of 4 s and hits both
binaries alike over 40 rounds. The 1.7% went away with more rounds: 120
rounds give -0.5% ± 0.7%, with the same CPU per request.

## 20. Five features removed (no speed change)

[cairophp/cairo#38](https://github.com/cairophp/cairo/pull/38) removes
`Cairo\Sql`, `Cairo\Http\Client`, the Revolt driver, the repeated query
detector (`$app->repeatedQueries()`) and the `cors()`, `rateLimit()` and
`gzip()` middleware. It is not a performance change: the reason is a smaller
set of extensions (4,582 lines less, and the Go MySQL driver with it). It is
here because this suite changed with it and because a removal is also
measured.

What changed in this suite: `apps/cairo/server.php` lost the routes
`/client`, `/sql-pgsql` and `/sql-mysql` and the `BENCH_REPEATED_QUERIES`
switch, and `run.sh` and `ab.sh` lost the three cases. The rows of
`Cairo\Http\Client` and `Cairo\Sql` in the tables above and in the README
are the record of their dates; a current Cairo cannot run them. Steps 1f,
2a, 10 and 13 are about `Cairo\Sql` and the detector and stay as history.

What it measured, as in step 19 (the cloud VM, the server on 2 CPUs with 4
PHP threads, wrk at 64 connections, 40 alternating rounds of 4 s), `main`
before it (1876d8f) against its last commit (3332ac7):

| Case | Before | After | Difference |
|---|---:|---:|---|
| hello | 50,832 | 50,016 | -0.9% ± 1.9%; CPU per request 38.8 to 39.4 µs |
| `/pgsql` | 18,033 | 17,630 | -1.8% ± 1.5%; CPU per request 104.0 to 106.0 µs |
| `/mysql`, first 40 rounds | 27,705 | 26,468 | -4.0% ± 1.4%; CPU per request 67.0 to 69.2 µs |
| `/mysql`, second 40 rounds | 27,639 | 27,452 | -0.3% ± 1.6%; CPU per request 66.7 to 67.3 µs |
| `/mysql`, all 80 rounds | | | -2.1% ± 1.1% |
| The binary of a native build | 29.35 MB | **23.22 MB** | -20.9% |

The first MySQL run stood out for a change that only removes code, so it was
checked. Profiles of both builds show no function that got slower: the
difference of the slow run is spread over the kernel, `libphp.so` (the same
file for both builds) and the binary, as in a slow stretch of the machine
(step 19, "The machine, and the benchmark of the core operations"). Per
request both builds make the same system calls (15.2 and 15.6) and thread
switches (0.67 and 0.61), the core operations of `coreops/` are within 3% in
both directions, and a second 40 rounds gave -0.3%. Reading: no change that this machine can measure. The
three routes lean one to two percent low, none by more than two standard
errors, and nothing points at a cause in the code.

## Tried and not kept

| Change | Result | Branch |
|---|---|---|
| PHP threads spin before waiting for an event | Worse (hello 21.9k → 19.2k): a spinning thread inside a Go callback holds a P that net/http needs | not kept |
| The socket poller wakes the PHP thread directly, skipping the fiber's goroutine | No difference: that goroutine wake runs on the same P and costs no thread switch | not kept |
| PHP threads wait for events in a C queue (inbox.c) instead of inside Go | No difference then: hello 49k either way, 76-80 µs CPU and 2.1 context switches per request. Done in step 19j together with the way of the answers, where it gains 8% | `experiment-c-inbox` |
| msgpack instead of the binary format | Not built: slower to decode in PHP without an extension | – |
| Pooled request buffers, and response headers written straight into the `ResponseWriter` | No difference in alternating runs (48-61k either way) | not kept |
| Fewer closures and `Context::current()` lookups per request in runtime.php | No difference (hello -0.8 %, json +2.8 %, 6 rounds): PHP is 16 % of hello's CPU on the cloud VM, and this was a small part of it | not kept |
| `Cairo\Pool` `processMax`: one connection limit for all threads (PDO MySQL and PostgreSQL) | First version -29 % on `/mysql-slow` under mixed load (moving a slot closed a connection on one thread and opened one on another, all the time). Reworked so only a thread above its fair share gives connections up: ties with per-thread pools (+1 %, 0 %, +1 %, -1 %). Per-thread pools with `max` = limit / threads already give the same cap | not kept |
| Merging identical upstream GETs in flight at once (`Cairo\Http\Client` option `coalesce`) | Upstream calls 89k → 355 under load, but only +1 % req/s and p99 -6 %. Dropped on 2026-11-19: only `Cairo\Http\Client` gets it (Guzzle and Laravel's `Http::` use curl), one request's call silently answering another can confuse, and the response cache already covers hot endpoints | `http-coalesce` (cairo#13, closed) |
| Isolated mode: each thread holds its running request and one more | Hello back to main (2,118 req/s) with the same p99, but the extra request waits behind a slow one | not kept (step 16) |
| Step 19i with the thread asleep on its own eventfd instead of inside Go | The waits were as fast, but a Go operation of one fiber took 46 µs against 33. Step 19j moved the sleep to C with the queues | not kept |
| Step 19j with the operations through the queue too | `Go::call` of one fiber 37.6 µs against 21.8; from 64 fibers 19% faster (1.95 µs). The single call decided | not kept |
| A wait of up to 1 ms on the thread itself before parking a fiber on its socket (step 6a), next to other requests | It cost 4 to 7% on `/mysql` once a socket wait was a wait of the thread (19l). Kept only in isolated mode, where the thread has nothing else to run | removed in 19l |
| The thread always sleeps in its epoll, also with no fiber on a socket | Not built: a wake-up there is a write and epoll's own wake-up, where the futex is one call, and routes that wait on no socket would pay it | – |
| Request and offload threads watch their inbox for 6 µs before they sleep on an `offload()` job | An empty job takes 19 µs, but a job of 50 µs takes 88 µs either way and pays 12 µs more CPU | not kept |
| The lists of the scheduler (step 19o) in `malloc` memory | Built first and replaced: outside `memory_limit` and `maxMemory()`, so timers left behind by waits that ended early could grow unseen | replaced in 19o |
| Pinning the fast level of the smallest operations (code and data layout) | Nothing to pin: the two levels are the machine's slow stretches (step 19), and layout moves these operations by about 1% | – |
| opcache's tracing JIT for the Laravel benchmark (`opcache.jit=tracing`, 128 MB buffer) | No difference in two alternating runs (373-383 and 360-369 without, 343-368 and 362-372 with): framework code is calls and hash lookups, which the JIT does not speed up | not kept |

## Where the time goes now

On hello at 64 connections, with 4 PHP threads on the cloud VM, Cairo spends
37.7 µs of CPU per request after step 19 (51.5 µs before it). PHP takes 5.4 µs
of that (Cairo's runtime: Request, Context, the response) and Cairo's C 1.4
µs, sampled with perf. The rest is Go's HTTP server and the kernel under it:
reading the request, writing the response and waking threads. So the HTTP
server is the next place to look, not the scheduler.

Before step 19, on the laptop with one PHP thread, hello cost about 65 µs:
about 17 µs in PHP and about 48 µs in Go, of which plain net/http is about
25 µs. Swoole spends about 33 µs in total: its HTTP server is C on the same
thread as PHP.
