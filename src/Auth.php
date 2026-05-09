<?php

namespace Lugit;

class Auth {
    public static function authenticate(): ?array {
        $users = Config::getUsers();
        
        $username = null;
        $password = null;
        
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            $username = $_SERVER['PHP_AUTH_USER'];
            $password = $_SERVER['PHP_AUTH_PW'] ?? null;
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
            if (str_starts_with($header, 'Basic ')) {
                $credentials = base64_decode(substr($header, 6));
                if ($credentials && str_contains($credentials, ':')) {
                    [$username, $password] = explode(':', $credentials, 2);
                }
            }
        } elseif (isset($_SERVER['HTTP_PROXY_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_PROXY_AUTHORIZATION'];
            if (str_starts_with($header, 'Basic ')) {
                $credentials = base64_decode(substr($header, 6));
                if ($credentials && str_contains($credentials, ':')) {
                    [$username, $password] = explode(':', $credentials, 2);
                }
            }
        }
        
        if ($username === null || $password === null) {
            return null;
        }

        foreach ($users as $user) {
            if ($user['username'] === $username && $user['password'] === $password) {
                return ['username' => $username];
            }
        }
        return null;
    }
    
    public static function parseAuthFromUrl(string $url): ?array {
        if (preg_match('#://([^:@]+):([^@]+)@#', $url, $matches)) {
            return ['username' => $matches[1], 'password' => $matches[2]];
        }
        return null;
    }
    
    public static function requireAuth(): ?array {
        $user = self::authenticate();
        if ($user === null) {
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="Git Server"');
            header('Content-Type: text/plain');
            echo "Authentication required\n";
            exit(1);
        }
        return $user;
    }
}

