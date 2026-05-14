#!/usr/bin/env php
<?php

/**
 * Lugit Test Runner
 *
 * Usage:
 *   php tests.php                   - Run all tests (unit + integration)
 *   php tests.php --unit            - Run only unit tests
 *   php tests.php --integration     - Run only integration tests
 *   php tests.php --port=9090       - Use port 9090 instead of 8080
 *   php tests.php --no-server       - Skip starting/stopping the PHP server
 *   php tests.php --ssh             - Include SSH tests
 */

// --- Parse options ---
$options = [
    'unit' => false,
    'integration' => false,
    'port' => 8080,
    'no-server' => false,
    'ssh' => false,
];

foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if ($arg === '--unit') $options['unit'] = true;
    elseif ($arg === '--integration') $options['integration'] = true;
    elseif ($arg === '--no-server') $options['no-server'] = true;
    elseif ($arg === '--ssh') $options['ssh'] = true;
    elseif (str_starts_with($arg, '--port=')) {
        $options['port'] = (int)substr($arg, 7);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Lugit Test Runner\n";
        echo "Usage: php tests.php [options]\n\n";
        echo "Options:\n";
        echo "  --unit              Run only unit tests\n";
        echo "  --integration       Run only integration tests\n";
        echo "  --port=N            Server port (default: 8080)\n";
        echo "  --no-server         Don't start/stop the PHP server\n";
        echo "  --ssh               Include SSH integration tests\n";
        echo "  --help              Show this help\n";
        exit(0);
    }
}

$onlyUnit = $options['unit'];
$onlyIntegration = $options['integration'];
$port = $options['port'];
$noServer = $options['no-server'];
$includeSsh = $options['ssh'];

// --- ANSI colors ---
define('C_GREEN', "\033[32m");
define('C_RED', "\033[31m");
define('C_YELLOW', "\033[33m");
define('C_CYAN', "\033[36m");
define('C_RESET', "\033[0m");
define('C_BOLD', "\033[1m");

function info(string $msg): void
{
    echo C_CYAN . "[INFO] " . C_RESET . $msg . "\n";
}

function success(string $msg): void
{
    echo C_GREEN . "[OK] " . C_RESET . $msg . "\n";
}

function warn(string $msg): void
{
    echo C_YELLOW . "[WARN] " . C_RESET . $msg . "\n";
}

function fail(string $msg): void
{
    echo C_RED . "[FAIL] " . C_RESET . $msg . "\n";
}

// --- Check prerequisites ---
$projectRoot = __DIR__;
$vendorDir = $projectRoot . '/vendor';

if (!is_dir($vendorDir)) {
    fail("Vendor directory not found. Run 'composer install' first.");
    exit(1);
}

// Find PHPUnit
$phpunitPaths = [
    $vendorDir . '/bin/phpunit',
    $vendorDir . '/phpunit/phpunit/phpunit',
    exec('which phpunit 2>/dev/null'),
];

$phpunit = null;
foreach ($phpunitPaths as $path) {
    if ($path && file_exists($path) && is_executable($path)) {
        $phpunit = $path;
        break;
    }
}
if ($path && !$phpunit) {
    $phpunit = $path;
}

if (!$phpunit || !file_exists($phpunit)) {
    // Try to find it via composer
    $result = exec('composer exec phpunit -- --version 2>/dev/null', $output, $code);
    if ($code === 0) {
        $phpunit = 'php ' . $vendorDir . '/bin/phpunit';
    } else {
        fail("PHPUnit not found. Install it globally or via composer:");
        echo "  composer require --dev phpunit/phpunit\n";
        echo "  Or: composer exec phpunit\n";
        exit(1);
    }
}

// --- Configuration setup ---
$configBackup = null;
$serverPid = null;
$exitCode = 0;

try {
    // Setup for integration tests
    if (!$onlyUnit) {
        $configPath = $projectRoot . '/config.json';
        $testConfigPath = $projectRoot . '/config.test.json';
        $reposPath = $projectRoot . '/test_repos';

        if (file_exists($configPath)) {
            $configBackup = file_get_contents($configPath);
            info("Backed up config.json");
        }

        // Create test repos directory
        if (!is_dir($reposPath)) {
            mkdir($reposPath, 0777, true);
        }

        // Create test user password
        $testPass = 'testpass';
        $testHash = password_hash($testPass, PASSWORD_ARGON2ID);

        // Write test config
        $testConfig = [
            'users' => [
                [
                    'username' => 'testuser',
                    'password' => $testHash,
                    'allow_cicd' => true,
                ],
                [
                    'username' => 'otheruser',
                    'password' => password_hash('otherpass', PASSWORD_ARGON2ID),
                    'allow_cicd' => false,
                ],
            ],
            'repositoriesPath' => $reposPath,
            'cacheFile' => $projectRoot . '/test_repo.cache',
            'excludedFolders' => [],
            'enableRegister' => true,
            'allow_cicd_default' => false,
            'routesCache' => false,
            'keysDump' => $projectRoot . '/test_keys.dump',
        ];

        file_put_contents($configPath, json_encode($testConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        success("Test config written to config.json");

        // Initialize empty keys dump
        file_put_contents($projectRoot . '/test_keys.dump', serialize(['u2k' => [], 'k2u' => []]));

        // Clean up any previous cache files
        @unlink($projectRoot . '/test_repo.cache');
        @unlink($projectRoot . '/routes.cache');

        // Start PHP server for integration tests
        if (!$noServer) {
            $docRoot = $projectRoot . '/public';
            $cmd = sprintf(
                'php -S localhost:%d -t %s > /dev/null 2>&1 & echo $!',
                $port,
                escapeshellarg($docRoot)
            );
            $serverPid = trim(exec($cmd));
            info("Starting PHP server on localhost:{$port} (PID: {$serverPid})");

            // Wait for server to be ready
            $maxWait = 10;
            $ready = false;
            for ($i = 0; $i < $maxWait; $i++) {
                $ch = @curl_init("http://localhost:{$port}/api/v1/user");
                if ($ch) {
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 2,
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Basic ' . base64_encode('testuser:testpass'),
                        ],
                    ]);
                    $response = @curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($code > 0) {
                        $ready = true;
                        break;
                    }
                }
                sleep(1);
            }

            if ($ready) {
                success("PHP server is ready on localhost:{$port}");
            } else {
                warn("Server may not be ready yet. Continuing anyway...");
            }

            // Enable routesCache=false by removing any cached routes
            @unlink($projectRoot . '/routes.cache');
        } else {
            info("Skipping server start (--no-server)");
        }
    }

    // --- Run PHPUnit ---
    echo "\n" . C_BOLD . "=== Running Tests ===" . C_RESET . "\n\n";

    $phpunitCmd = sprintf('%s --configuration %s/phpunit.xml', $phpunit, escapeshellarg($projectRoot));

    if ($onlyUnit) {
        $phpunitCmd .= ' --testsuite unit';
    } elseif ($onlyIntegration) {
        $phpunitCmd .= ' --testsuite integration';
    }

    if (!$includeSsh) {
        $phpunitCmd .= ' --exclude-group git-ssh';
    }

    if ($onlyUnit) {
        // For unit-only runs, no need for integration setup
        $phpunitCmd = sprintf('%s --configuration %s/phpunit.xml --testsuite unit', $phpunit, escapeshellarg($projectRoot));
    }

    // Set env var so tests know the server port
    $phpunitEnv = 'LUGIT_TEST_PORT=' . $port;

    putenv($phpunitEnv);
    echo "Running: $phpunitCmd\n\n";

    passthru($phpunitCmd, $exitCode);

    echo "\n";

} catch (\Throwable $e) {
    fail("Error: " . $e->getMessage());
    $exitCode = 1;
} finally {
    // --- Cleanup ---

    // Stop PHP server
    if ($serverPid) {
        info("Stopping PHP server (PID: {$serverPid})");
        if (function_exists('posix_kill')) {
            posix_kill((int)$serverPid, 15);
        } else {
            exec('kill ' . (int)$serverPid . ' 2>/dev/null');
        }
        // Wait for process to die
        for ($i = 0; $i < 5; $i++) {
            if (!@file_exists("/proc/{$serverPid}")) break;
            usleep(200000);
        }
        // Force kill if still alive
        if (@file_exists("/proc/{$serverPid}")) {
            exec('kill -9 ' . (int)$serverPid . ' 2>/dev/null');
        }
    }

    // Restore original config
    if ($configBackup !== null) {
        file_put_contents($configPath, $configBackup);
        success("Restored original config.json");
    }

    // Clean up test files
    $testFiles = [
        $projectRoot . '/test_repo.cache',
        $projectRoot . '/test_keys.dump',
        $projectRoot . '/test_repos',
        $projectRoot . '/config.test.json',
    ];
    foreach ($testFiles as $f) {
        if (is_dir($f)) {
            exec('rm -rf ' . escapeshellarg($f));
        } elseif (file_exists($f)) {
            unlink($f);
        }
    }

    // Clean routes cache that may have been created
    @unlink($projectRoot . '/routes.cache');
}

// --- Summary ---
echo "\n" . C_BOLD . str_repeat("=", 40) . C_RESET . "\n";
if ($exitCode === 0) {
    success("All tests passed!");
} else {
    fail("Some tests failed (exit code: {$exitCode})");
}
echo "\n";

exit($exitCode);
