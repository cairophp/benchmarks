<?php
// bench.php <path to http.php>: ns per call of Request::header() and Request::query() on a request with a browser's 13 headers.
require $argv[1];
$headers = [];
foreach (['Accept', 'Accept-Encoding', 'Accept-Language', 'Authorization', 'Connection', 'Cookie', 'Origin', 'Referer', 'Sec-Fetch-Mode', 'Sec-Fetch-Site', 'User-Agent', 'X-Forwarded-For', 'X-Forwarded-Proto', 'X-Request-Id'] as $h) {
    $headers[$h] = ['value of ' . $h];
}
$event = ['method' => 'GET', 'path' => '/api/items/42', 'query' => 'page=2&sort=name', 'headers' => $headers, 'params' => ['id' => '42'], 'body' => ''];
$n = 300000;
$cases = [
    'header(Authorization)' => static fn (Cairo\Http\Request $r) => $r->header('Authorization'),
    'header(X-Request-Id), the last one' => static fn (Cairo\Http\Request $r) => $r->header('X-Request-Id'),
    'header(accept-language), lower case' => static fn (Cairo\Http\Request $r) => $r->header('accept-language'),
    'header(X-Missing)' => static fn (Cairo\Http\Request $r) => $r->header('X-Missing'),
    'query(page) once per request' => static fn (Cairo\Http\Request $r) => $r->query('page'),
    'query(page) and query(sort)' => static fn (Cairo\Http\Request $r) => $r->query('page') . $r->query('sort'),
    'the handler: 3 headers, 1 query, 1 param' => static fn (Cairo\Http\Request $r) => $r->header('Authorization') . $r->header('X-Request-Id') . $r->header('Accept-Language') . $r->query('page') . $r->param('id'),
];
foreach ($cases as $name => $f) {
    $best = INF;
    for ($round = 0; $round < 5; $round++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $f(new Cairo\Http\Request($event));
        }
        $best = min($best, (hrtime(true) - $t) / $n);
    }
    printf("%-45s %6.0f ns\n", $name, $best);
}
