#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lugit\Config;
use Lugit\Utils;
use Lugit\RepoConfig;
use Lugit\RepoCache;

Config::init(__DIR__ . '/config.json');
RepoCache::init(Config::getCacheFile());

$basePath = Config::getRepositoriesPath();
$excluded = Config::getExcludedFolders();

echo "Migrating repositories to user-based structure...\n";
echo "Base path: $basePath\n\n";

if (!is_dir($basePath)) {
    echo "Error: repositories path does not exist.\n";
    exit(1);
}

$migrated = 0;
$skipped = 0;
$errors = 0;

$users = Config::getUsers();
$userMap = [];
foreach ($users as $user) {
    $userMap[$user['username']] = true;
}

$entries = scandir($basePath);
foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (in_array($entry, $excluded)) continue;
    
    $sourcePath = $basePath . '/' . $entry;
    
    if (!is_dir($sourcePath)) continue;
    if (!Utils::isGitRepo($sourcePath)) continue;
    
    $config = RepoConfig::load($sourcePath);
    $allowedUsers = $config->allowedUsers;
    
    if (empty($allowedUsers)) {
        echo "⚠ Skipping '$entry': no allowed users in config\n";
        $skipped++;
        continue;
    }
    
    $owner = $allowedUsers[0];
    
    if (!isset($userMap[$owner])) {
        echo "⚠ Skipping '$entry': owner '$owner' is not a system user\n";
        $skipped++;
        continue;
    }
    
    $userDir = $basePath . '/' . $owner;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }
    
    $destPath = $userDir . '/' . $entry;
    
    if (is_dir($destPath)) {
        echo "⚠ Skipping '$entry': destination already exists at $destPath\n";
        $skipped++;
        continue;
    }
    
    if (rename($sourcePath, $destPath)) {
        echo "✓ Migrated: $entry -> $owner/$entry\n";
        
        RepoCache::addRepo($owner, $entry, [
            'public' => $config->public,
            'allowedUsers' => $config->allowedUsers
        ]);
        
        $migrated++;
    } else {
        echo "✗ Error migrating: $entry\n";
        $errors++;
    }
}

echo "\n";
echo "Migration complete!\n";
echo "Migrated: $migrated\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";

if ($migrated > 0) {
    echo "\nNow run: php updateCache.php\n";
}