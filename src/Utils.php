<?php

namespace Lugit;

class Utils {
    public static function getRepoPath(string $username, string $repoName): string {
        $basePath = Config::getRepositoriesPath();
        $excluded = Config::getExcludedFolders();
        
        if (in_array($username, $excluded)) {
            throw new \RuntimeException("User not found");
        }
        if (in_array($repoName, $excluded)) {
            throw new \RuntimeException("Repository not found: $repoName");
        }
        
        $repoPath = $basePath . '/' . $username . '/' . $repoName;
        if (!is_dir($repoPath)) {
            throw new \RuntimeException("Repository not found: $username/$repoName");
        }
        
        return $repoPath;
    }

    public static function findRepoPath(string $username, string $repoName): ?string {
        $basePath = Config::getRepositoriesPath();
        $excluded = Config::getExcludedFolders();
        
        if (in_array($username, $excluded) || in_array($repoName, $excluded)) {
            return null;
        }
        
        $repoPath = $basePath . '/' . $username . '/' . $repoName;
        if (!is_dir($repoPath)) {
            return null;
        }
        
        return $repoPath;
    }

    public static function isValidRepoName(string $name): bool {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $name) === 1 && strpos($name, '..') === false;
    }

    public static function isValidUsername(string $username): bool {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $username) === 1 && strpos($username, '..') === false;
    }

    public static function listRepositories(): array {
        $basePath = Config::getRepositoriesPath();
        $excluded = Config::getExcludedFolders();
        
        if (!is_dir($basePath)) {
            return [];
        }
        
        $result = [];
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
                if (is_dir($repoPath) && is_dir($repoPath . '/objects') && is_dir($repoPath . '/refs')) {
                    $result[] = $username . '/' . $repoName;
                }
            }
        }
        
        return $result;
    }

    public static function isGitRepo(string $path): bool {
        return is_dir($path . '/objects') && is_dir($path . '/refs') && file_exists($path . '/HEAD');
    }

    public static function pktLine(string $data): string {
        $len = strlen($data) + 4;
        return sprintf("%04x", $len) . $data;
    }

    public static function parsePktLine(string $data): string {
        if (strlen($data) < 4) {
            return '';
        }
        $len = hexdec(substr($data, 0, 4));
        return substr($data, 4, $len - 4);
    }

    public static function runGit(string $cmd, string $cwd): array {
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = proc_open($cmd, $descriptor, $pipes, $cwd);
        
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to run: $cmd");
        }
        
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $exitCode = proc_close($process);
        
        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exitCode' => $exitCode
        ];
    }

    public static function createBareRepo(string $path): void {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
		$cmd = "git init --bare " . escapeshellarg($path);
        $result = self::runGit($cmd, dirname($path));
        
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException("Failed to create repository: " . $result['stderr']);
        }
    }

    public static function deleteRepo(string $path): void {
        if (!is_dir($path)) {
            throw new \RuntimeException("Repository not found: $path");
        }
        
        $cmd = "rm -rf '" . escapeshellarg($path) . "'";
        exec($cmd);
    }
}