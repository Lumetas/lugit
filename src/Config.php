<?php
namespace Lugit;

class Config {
    private static ?array $config = null;
    private static ?string $configPath = null;

    public static function init(string $configPath): void {
        self::$configPath = $configPath;
        self::$config = null;
    }

    public static function load(): array {
        if (self::$config === null) {
            $path = self::$configPath ?? dirname(__DIR__) . '/config.json';
            if (!file_exists($path)) {
                throw new \RuntimeException("Config file not found: $path");
            }
            $content = file_get_contents($path);
            $content = preg_replace('/\/\/.*$/m', '', $content);
            self::$config = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON in config: " . json_last_error_msg());
            }
        }
        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed {
        $config = self::load();
        $keys = explode('.', $key);
        $value = $config;
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public static function getRepositoriesPath(): string {
        return self::get('repositoriesPath', dirname(__DIR__) . '/repos');
    }

    public static function getExcludedFolders(): array {
        return self::get('excludedFolders', []);
    }

    public static function getUsers(): array {
        return self::get('users', []);
    }

	public static function getUser(string $username): ?array {
		$users = self::getUsers();
		foreach ($users as $u) {
			if ($u['username'] === $username) {
				return $u;
			}
		}
		return null;
	}

	public static function setUsers(array $users): void {
		self::$config['users'] = $users;
	}

    public static function reload(): void {
        self::$config = null;
    }

	public static function save(): void {
		$path = self::$configPath ?? dirname(__DIR__) . '/config.json';
		file_put_contents($path, json_encode(self::$config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}

