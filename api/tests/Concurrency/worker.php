<?php

/*
 * One worker of the concurrency tests, like one PHP-FPM worker: boots the app, waits at the
 * barrier until every worker is ready, then handles its share of the requests through the HTTP
 * kernel and prints the responses as JSON.
 *
 * Usage: php worker.php <requests.json> <barrier dir> <worker number>
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

[, $requestsFile, $barrier, $worker] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
// Load config, providers and routes now, so every worker is ready before the barrier opens.
$kernel->bootstrap();

touch("{$barrier}/ready.{$worker}");
while (! file_exists("{$barrier}/go")) {
    usleep(200);
}

$responses = [];
foreach (json_decode(file_get_contents($requestsFile), true) as [$uri, $headers]) {
    // Each request starts with fresh guards, as in a new PHP-FPM request.
    $app['auth']->forgetGuards();
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($uri, 'GET', server: $server);
    $response = $kernel->handle($request);
    $responses[] = [
        'status' => $response->getStatusCode(),
        'billed' => $response->headers->get('Billed'),
        'retry_after' => $response->headers->get('Retry-After'),
        'json' => json_decode((string) $response->getContent(), true),
    ];
    $kernel->terminate($request, $response);
}

echo json_encode($responses);
