<?php

// A request shaped like an API's: path parameter, query string, browser
// headers, two MySQL queries, a JSON answer of about 1.5 KB with five headers.

declare(strict_types=1);

use Cairo\App;
use Cairo\Http\Request;
use Cairo\Http\Response;
use Cairo\Pool;

require __DIR__ . '/../apps/workload.php';

$threads = (int) bench_env('BENCH_THREADS', '4');
$my = new Pool(fn (): PDO => bench_pdo('mysql'), max: 10);

$answer = static function (Request $req, array $data): Response {
    return new Response(200, [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'private, no-store',
        'X-Request-Id' => $req->header('X-Request-Id') ?? '-',
        'X-RateLimit-Remaining' => '59',
        'Vary' => 'Accept-Encoding, Authorization',
    ], json_encode($data, JSON_THROW_ON_ERROR));
};
$queries = static function (PDO $pdo, int $id): array {
    $one = $pdo->prepare('SELECT id, name FROM bench_items WHERE id = ?');
    $one->execute([$id]);
    $list = $pdo->prepare('SELECT id, name FROM bench_items WHERE id BETWEEN ? AND ?');
    $list->execute([$id, $id + 19]);
    return ['item' => $one->fetch(PDO::FETCH_ASSOC), 'related' => $list->fetchAll(PDO::FETCH_ASSOC)];
};

$app = new App();
$app->threads($threads);
if (bench_env('BENCH_CONCURRENCY', 'fibers') === 'isolated') {
    $app->concurrency('isolated');
}
$http = $app->http(':8080');
foreach (array_filter(explode(',', bench_env('REAL_MW', ''))) as $mw) {
    $http->use(match ($mw) {
        'log' => Cairo\Middleware::accessLog(),
        'id' => Cairo\Middleware::requestId(),
        'timeout' => Cairo\Middleware::timeout(5.0),
    });
}
$big = [100 => str_repeat('{"id":1234,"name":"item 1234"},', 3334), 1000 => str_repeat('{"id":1234,"name":"item 1234"},', 33334)];
$http->get('/api/big/{kb}', fn (Request $req): Response => new Response(200, ['Content-Type' => 'application/json'], $big[(int) $req->param('kb')] ?? ''));
$http->post('/api/upload', fn (Request $req): Response => Response::json(['bytes' => strlen($req->body())]));
$http->get('/hello', fn (): Response => Response::text('Hello, World!'));
$http->get('/api/items/{id}', function (Request $req) use ($my, $answer, $queries): Response {
    if (!str_starts_with($req->header('Authorization') ?? '', 'Bearer ')) {
        return Response::json(['error' => 'unauthorized'], 401);
    }
    $id = max(1, min(BENCH_ROWS - 20, (int) $req->param('id')));
    $data = $my->with(static fn (PDO $pdo): array => $queries($pdo, $id));
    return $answer($req, $data + ['page' => (int) ($req->query('page') ?? 1), 'lang' => $req->header('Accept-Language')]);
});
// A public page of the same data, cached for a minute: no Authorization, no Cookie.
$http->get('/api/catalog/{id}', function (Request $req) use ($my, $queries): Response {
    $id = max(1, min(BENCH_ROWS - 20, (int) $req->param('id')));
    $data = $my->with(static fn (PDO $pdo): array => $queries($pdo, $id));
    return new Response(200, [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'public, max-age=60',
        'X-RateLimit-Remaining' => '59',
        'Vary' => 'Accept-Encoding',
    ], json_encode($data + ['page' => (int) ($req->query('page') ?? 1)], JSON_THROW_ON_ERROR));
})->cache(ttl: 60);
$http->get('/api/static/{id}', function (Request $req) use ($answer): Response {
    if (!str_starts_with($req->header('Authorization') ?? '', 'Bearer ')) {
        return Response::json(['error' => 'unauthorized'], 401);
    }
    $id = max(1, min(BENCH_ROWS - 20, (int) $req->param('id')));
    $rows = [];
    for ($i = 0; $i < 20; $i++) {
        $rows[] = ['id' => $id + $i, 'name' => 'item ' . ($id + $i)];
    }
    $data = ['item' => $rows[0], 'related' => $rows];
    return $answer($req, $data + ['page' => (int) ($req->query('page') ?? 1), 'lang' => $req->header('Accept-Language')]);
});
$http->post('/api/orders', function (Request $req) use ($my, $answer, $queries): Response {
    $order = $req->json();
    if (!is_array($order) || !isset($order['items']) || !is_array($order['items'])) {
        return Response::json(['error' => 'bad order'], 422);
    }
    $id = max(1, min(BENCH_ROWS - 20, (int) ($order['items'][0]['id'] ?? 1)));
    $data = $my->with(static fn (PDO $pdo): array => $queries($pdo, $id));
    return $answer($req, $data + ['lines' => count($order['items']), 'note' => $order['note'] ?? '']);
});
return $app;
