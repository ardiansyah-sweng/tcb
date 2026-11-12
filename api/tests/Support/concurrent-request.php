<?php

/*
| Child process for tests/Feature/Concurrency: sends one API request through
| the HTTP kernel in its own PHP process (its own database connection) and
| prints {"status": int, "body": mixed} as JSON.
|
| Usage: php concurrent-request.php <METHOD> <URI> <TOKEN> [JSON_BODY]
| The parent passes DB_DATABASE=tcb_test in the environment.
*/

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

[, $method, $uri, $token] = $argv;
$body = $argv[4] ?? '{}';

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$request = Request::create($uri, $method, server: [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_AUTHORIZATION' => "Bearer {$token}",
], content: $body);

$response = $kernel->handle($request);
fwrite(STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
]));
$kernel->terminate($request, $response);
