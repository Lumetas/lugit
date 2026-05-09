<?php
namespace Lugit;
class RepoConfig {
    public function __construct(
        public array $allowedUsers = [],
        public bool $public = false
    ) {}

    public static function load(string $repoPath): self {
        $configPath = $repoPath . '/lugit.json';
        if (!file_exists($configPath)) {
            return new self();
        }
        $content = file_get_contents($configPath);
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in repo config: " . json_last_error_msg());
        }
        return new self(
            allowedUsers: $data['allowedUsers'] ?? [],
            public: $data['public'] ?? false
        );
    }

    public function save(string $repoPath): void {
        $configPath = $repoPath . '/lugit.json';
        $data = [
            'allowedUsers' => $this->allowedUsers,
            'public' => $this->public
        ];
        file_put_contents($configPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function addUser(string $username): void {
        if (!in_array($username, $this->allowedUsers)) {
            $this->allowedUsers[] = $username;
        }
    }

    public function removeUser(string $username): void {
        $this->allowedUsers = array_values(array_filter($this->allowedUsers, fn($u) => $u !== $username));
    }

    public function hasUser(string $username): bool {
        return in_array($username, $this->allowedUsers);
    }
}
