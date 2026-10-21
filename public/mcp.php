<?php

declare(strict_types=1);

// Router script for `php -S`: every HTTP request becomes one MCP streamable-HTTP exchange.

use CodeMap\Config\Config;
use CodeMap\Mcp\ServerFactory;
use CodeMap\Storage\Database;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require dirname(__DIR__).'/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ('/health' === $path) {
    header('Content-Type: text/plain');
    echo "ok\n";

    return;
}
if ('/mcp' !== $path) {
    http_response_code(404);
    echo "Not found. The MCP endpoint is /mcp\n";

    return;
}

$config = Config::load();
$db = new Database($config->databasePath);
$server = ServerFactory::create($db, dirname($config->databasePath).'/sessions');

$factory = new Psr17Factory();
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();

$transport = new StreamableHttpTransport(
    $request,
    $factory,
    $factory,
    middleware: [new CorsMiddleware(), new DnsRebindingProtectionMiddleware($config->allowedHosts)],
);

(new SapiEmitter())->emit($server->run($transport));
