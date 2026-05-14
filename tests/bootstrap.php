<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Lugit\Config;

const TEST_TEMP_DIR = __DIR__ . '/_temp';

// Pre-compute password hashes once (avoids slow Argon2id on every setUp)
define('TEST_PASS_HASH', password_hash('testpass', PASSWORD_ARGON2ID));
define('OTHER_PASS_HASH', password_hash('otherpass', PASSWORD_ARGON2ID));

function testTempDir(): string
{
    $dir = TEST_TEMP_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return realpath($dir);
}

function testTempReposDir(): string
{
    $dir = testTempDir() . '/repos';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function createTestConfig(string $reposPath, array $extra = []): string
{
    $config = array_merge([
        'users' => [
            ['username' => 'testuser', 'password' => TEST_PASS_HASH, 'allow_cicd' => true],
            ['username' => 'otheruser', 'password' => OTHER_PASS_HASH, 'allow_cicd' => false],
        ],
        'repositoriesPath' => $reposPath,
        'cacheFile' => testTempDir() . '/repo.cache',
        'excludedFolders' => [],
        'enableRegister' => true,
        'allow_cicd_default' => false,
        'routesCache' => false,
        'keysDump' => testTempDir() . '/keys.dump'
    ], $extra);

    $path = testTempDir() . '/config_' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    Config::init($path);
    return $path;
}

function initTestConfigFromArray(string $reposPath, array $extra = []): void
{
    $config = array_merge([
        'users' => [
            ['username' => 'testuser', 'password' => TEST_PASS_HASH, 'allow_cicd' => true],
            ['username' => 'otheruser', 'password' => OTHER_PASS_HASH, 'allow_cicd' => false],
        ],
        'repositoriesPath' => $reposPath,
        'cacheFile' => testTempDir() . '/repo.cache',
        'excludedFolders' => [],
        'enableRegister' => true,
        'allow_cicd_default' => false,
        'routesCache' => false,
        'keysDump' => testTempDir() . '/keys.dump',
    ], $extra);

    Config::reload();
    $ref = new \ReflectionClass(Config::class);
    $prop = $ref->getProperty('config');
    $prop->setAccessible(true);
    $prop->setValue($config);

    $pathProp = $ref->getProperty('configPath');
    $pathProp->setAccessible(true);
    $path = testTempDir() . '/config_direct.json';
    $pathProp->setValue($path);

    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function createTestBareRepo(string $username, string $repoName): string
{
    $reposPath = testTempReposDir();
    $repoPath = $reposPath . '/' . $username . '/' . $repoName;
    if (!is_dir(dirname($repoPath))) {
        mkdir(dirname($repoPath), 0777, true);
    }
    exec('git init --bare ' . escapeshellarg($repoPath) . ' 2>/dev/null');
    return $repoPath;
}

function cleanupTestDir(): void
{
    $dir = TEST_TEMP_DIR;
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}

function setPrivateProperty(object $obj, string $property, mixed $value): void
{
    $class = get_class($obj);
    $ref = new \ReflectionClass($class);
    while ($ref && !$ref->hasProperty($property)) {
        $ref = $ref->getParentClass();
    }
    if (!$ref) {
        throw new \InvalidArgumentException("Property $property not found in class hierarchy of $class");
    }
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($obj, $value);
}

function getPrivateProperty(object $obj, string $property): mixed
{
    $class = get_class($obj);
    $ref = new \ReflectionClass($class);
    while ($ref && !$ref->hasProperty($property)) {
        $ref = $ref->getParentClass();
    }
    if (!$ref) {
        throw new \InvalidArgumentException("Property $property not found in class hierarchy of $class");
    }
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    return $prop->getValue($obj);
}

class SendErrorException extends \RuntimeException {}
