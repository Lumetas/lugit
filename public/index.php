<?php

namespace Lugit;

require_once __DIR__ . '/../vendor/autoload.php';
/* require_once __DIR__ . '/../src/Utils.php'; */
/* require_once __DIR__ . '/../src/GitHttpServer.php'; */
/* require_once __DIR__ . '/../src/GitApi.php'; */
/* require_once __DIR__ . '/../src/RepoPage.php'; */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    $api = new GitApi();
    $api->handle();
} elseif (preg_match('#^/?repos/([^/]+)/?$#', $path, $matches) && !str_contains($path, '/info/') && !str_contains($path, '/git-')) {
    $page = new RepoPage();
    $page->handle();
} else {
    $server = new GitHttpServer();
    $server->handle();
}
