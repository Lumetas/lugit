<?php

namespace Lugit;

class RepoCache {
    private static ?string $cacheFile = null;
    private static ?array $cache = null;

    public static function init(string $cacheFile): void {
        self::$cacheFile = $cacheFile;
        self::$cache = null;
    }

    public static function getCacheFile(): string {
        if (self::$cacheFile === null) {
            self::$cacheFile = Config::get('cacheFile', dirname(__DIR__) . '/repo.cache');
        }
        return self::$cacheFile;
    }

    public static function load(): array {
        if (self::$cache === null) {
            $path = self::getCacheFile();
            if (file_exists($path)) {
                $content = file_get_contents($path);
                self::$cache = unserialize($content) ?: [];
            } else {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    public static function save(): void {
        $path = self::getCacheFile();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, serialize(self::$cache));
    }

    public static function rebuild(): void {
        $basePath = Config::getRepositoriesPath();
        $excluded = Config::getExcludedFolders();
        $cache = [];

        if (is_dir($basePath)) {
            $users = scandir($basePath);
            foreach ($users as $username) {
                if ($username === '.' || $username === '..') continue;
                if (in_array($username, $excluded)) continue;

                $userPath = $basePath . '/' . $username;
                if (!is_dir($userPath)) continue;

                $repos = scandir($userPath);
                foreach ($repos as $repoName) {
                    if ($repoName === '.' || $repoName === '..') continue;
                    if (in_array($repoName, $excluded)) continue;

                    $repoPath = $userPath . '/' . $repoName;
                    if (!Utils::isGitRepo($repoPath)) continue;

                    try {
                        $config = RepoConfig::load($repoPath);
                        $cache[$username][$repoName] = [
                            'public' => $config->public,
                            'allowedUsers' => $config->allowedUsers
                        ];
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        }

        self::$cache = $cache;
        self::save();
    }

    public static function hasRepo(string $username, string $repoName): bool {
        $cache = self::load();
        return isset($cache[$username][$repoName]);
    }

    public static function getRepo(string $username, string $repoName): ?array {
        $cache = self::load();
        return $cache[$username][$repoName] ?? null;
    }

    public static function addRepo(string $username, string $repoName, array $config): void {
        $cache = self::load();
        if (!isset($cache[$username])) {
            $cache[$username] = [];
        }
        $cache[$username][$repoName] = $config;
        self::$cache = $cache;
        self::save();
    }

    public static function updateRepo(string $username, string $repoName, array $config): void {
        $cache = self::load();
        if (isset($cache[$username][$repoName])) {
            $cache[$username][$repoName] = array_merge($cache[$username][$repoName], $config);
            self::$cache = $cache;
            self::save();
        }
    }

    public static function removeRepo(string $username, string $repoName): void {
        $cache = self::load();
        if (isset($cache[$username][$repoName])) {
            unset($cache[$username][$repoName]);
            if (empty($cache[$username])) {
                unset($cache[$username]);
            }
            self::$cache = $cache;
            self::save();
        }
    }

    public static function getUserRepos(string $username): array {
        $cache = self::load();
        return $cache[$username] ?? [];
    }

    public static function getAll(): array {
        return self::load();
    }
}