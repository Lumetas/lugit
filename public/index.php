<?php

namespace Lugit;

require_once __DIR__ . '/../vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/' || $path === '') {
	readfile(__DIR__ . '/../src/static/index.html');
} else if ($path == '/_script.js') {
	header('Content-Type: text/javascript');
    readfile(__DIR__ . '/../src/static/script.js');
} else if ($path == '/_style.css') {
	header('Content-Type: text/css');
    readfile(__DIR__ . '/../src/static/style.css');
} elseif (str_starts_with($path, '/api/')) {
    $api = new GitApi();
    $api->handle();
} elseif (preg_match('#^/?repos/([^/]+)/?$#', $path, $matches) && !str_contains($path, '/info/') && !str_contains($path, '/git-')) {
    $page = new RepoPage();
    $page->handle();
} else {
    $server = new GitHttpServer();
    $server->handle();
}
