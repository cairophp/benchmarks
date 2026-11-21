<?php

// What each core-engine operation costs today, measured inside one request on
// an otherwise idle server: GET /ops returns one line per operation with the
// elapsed time and the process CPU time (all threads) per iteration.

declare(strict_types=1);

use Cairo\App;
use Cairo\Channel;
use Cairo\Context;
use Cairo\Http\Response;
use Cairo\Internal\Go;

use function Cairo\async;
use function Cairo\await;
use function Cairo\offload;

/**
 * Each case in 25 short pieces, in turn with the pieces of the other cases, and the fastest
 * piece of each. Not five long rounds in a row: the cloud machine runs everything up to twice
 * as slow for 0.1 to 0.7 s at a time (IMPROVEMENTS.md, step 19), which can cover all the rounds
 * of a case.
 *
 * @param array<string, array{int, Closure}> $cases
 * @return array<string, array{float, float}> elapsed ns and CPU ns per iteration
 */
function measure(array $cases): array
{
    $cpu = static function (): float {
        $u = getrusage();
        return ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1e9 + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) * 1e3;
    };
    $best = [];
    foreach ($cases as $name => [$n, $fn]) {
        $fn(min($n, 640));   // warm up
        $best[$name] = [INF, INF];
    }
    for ($round = 0; $round < 25; $round++) {
        foreach ($cases as $name => [$n, $fn]) {
            $n = max(64, intdiv($n, 320) * 64);
            $c0 = $cpu();
            $t0 = hrtime(true);
            $fn($n);
            $t = (hrtime(true) - $t0) / $n;
            $c = ($cpu() - $c0) / $n;
            if ($t < $best[$name][0]) {
                $best[$name] = [$t, $c];
            }
        }
    }
    return $best;
}

$app = new App();
$app->threads((int) (getenv('BENCH_THREADS') ?: 1));
$app->offloadThreads(1);

$http = $app->http(':8080');
$http->get('/hello', fn (): Response => Response::text('Hello, World!'));
$http->get('/ops', function (): Response {
    $cases = [
        'empty loop' => [2_000_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
            }
        }],
        'Context::current()' => [1_000_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                Context::current();
            }
        }],
        'Context::set() + Context::get()' => [1_000_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                Context::set('k', $i);
                Context::get('k');
            }
        }],
        'Channel with room: send + recv, nobody waits' => [500_000, static function (int $n): void {
            $ch = new Channel(8);
            for ($i = 0; $i < $n; $i++) {
                $ch->send($i);
                $ch->recv();
            }
        }],
        'Go operation on the PHP thread (Go::callNow)' => [200_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                Go::callNow('cache.purge', ['tags' => ['none']]);
            }
        }],
        'Go operation on a goroutine (Go::call)' => [20_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                Go::call('cache.purge', ['tags' => ['none']]);
            }
        }],
        'Cairo\sleep(0)' => [20_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                Cairo\sleep(0.0);
            }
        }],
        'async() + await(), task never waits' => [20_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                await(async(static fn (): int => 1));
            }
        }],
        'Context::withTimeout() + cancel' => [20_000, static function (int $n): void {
            $ctx = Context::current();
            for ($i = 0; $i < $n; $i++) {
                $child = $ctx->withTimeout(5.0);
                $child->cancel();
                unset($child);
            }
        }],
        'Channel round trip between two fibers (2 blocked receives)' => [10_000, static function (int $n): void {
            $ping = new Channel();
            $pong = new Channel();
            $echo = async(static function () use ($ping, $pong): void {
                foreach ($ping as $v) {
                    $pong->send($v);
                }
            });
            for ($i = 0; $i < $n; $i++) {
                $ping->send($i);
                $pong->recv();
            }
            $ping->close();
            await($echo);
        }],
        // The same wait from 64 fibers at once: threads stay awake, as on a loaded server.
        'Cairo\sleep(0), 64 fibers at once (per sleep)' => [640_000, static function (int $n): void {
            $tasks = [];
            for ($f = 0; $f < 64; $f++) {
                $tasks[] = async(static function () use ($n): void {
                    for ($i = intdiv($n, 64); $i > 0; $i--) {
                        Cairo\sleep(0.0);
                    }
                });
            }
            foreach ($tasks as $task) {
                await($task);
            }
        }],
        'Go::call, 64 fibers at once (per call)' => [640_000, static function (int $n): void {
            $tasks = [];
            for ($f = 0; $f < 64; $f++) {
                $tasks[] = async(static function () use ($n): void {
                    for ($i = intdiv($n, 64); $i > 0; $i--) {
                        Go::call('cache.purge', ['tags' => ['none']]);
                    }
                });
            }
            foreach ($tasks as $task) {
                await($task);
            }
        }],
        'offload(), a job on another PHP thread' => [10_000, static function (int $n): void {
            for ($i = 0; $i < $n; $i++) {
                offload('strlen', 'x');
            }
        }],
    ];
    $out = '';
    foreach (measure($cases) as $name => [$t, $c]) {
        $out .= sprintf("%-62s %9.0f ns elapsed %9.0f ns CPU\n", $name, $t, $c);
    }
    return Response::text($out);
});

return $app;
