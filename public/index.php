<?php

namespace Lugit;

require_once __DIR__ . '/../vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = $_SERVER['QUERY_STRING'] ?? '';

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
} elseif (preg_match('#^/?([^/]+)/([^/]+)/?$#', $path, $matches)) {
    $username = $matches[1];
    $repoName = $matches[2];
    
    if (str_contains($path, '/info/') || str_contains($path, '/git-upload') || str_contains($path, '/git-receive')) {
        $server = new GitHttpServer();
        $server->handle();
    } else {
        $page = new RepoPage();
        $page->handle();
    }
} else {
    $server = new GitHttpServer();
    $server->handle();
}