#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lugit\Config;
use Lugit\RepoCache;

Config::init(__DIR__ . '/config.json');
RepoCache::init(Config::getCacheFile());

echo "Rebuilding repository cache...\n";

RepoCache::rebuild();

$cache = RepoCache::getAll();
$totalRepos = 0;
$totalUsers = 0;

foreach ($cache as $userRepos) {
    $totalUsers++;
    $totalRepos += count($userRepos);
}

echo "Cache updated successfully!\n";
echo "Total users: $totalUsers\n";
echo "Total repositories: $totalRepos\n";
echo "Cache file: " . RepoCache::getCacheFile() . "\n";