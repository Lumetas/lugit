<?php

namespace Tests\Unit;

use Lugit\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private string $configPath;

    protected function setUp(): void
    {
        $users = [
            ['username' => 'alice', 'password' => 'hash1', 'allow_cicd' => true],
            ['username' => 'bob', 'password' => 'hash2', 'allow_cicd' => false],
        ];
        $config = [
            'users' => $users,
            'repositoriesPath' => '/tmp/test_repos',
            'cacheFile' => '/tmp/test_cache',
            'excludedFolders' => ['trash'],
            'enableRegister' => false,
            'routesCache' => false,
        ];
        $this->configPath = tempnam(sys_get_temp_dir(), 'config_') . '.json';
        file_put_contents($this->configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        Config::init($this->configPath);
    }

    protected function tearDown(): void
    {
        Config::reload();
        if (file_exists($this->configPath)) {
            unlink($this->configPath);
        }
    }

    public function testLoadReturnsArray(): void
    {
        $config = Config::load();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('users', $config);
    }

    public function testGetReturnsValue(): void
    {
        $this->assertEquals('/tmp/test_repos', Config::get('repositoriesPath'));
    }

    public function testGetReturnsDefaultOnMissing(): void
    {
        $this->assertEquals('default', Config::get('nonexistent', 'default'));
    }

    public function testGetWithDotNotation(): void
    {
        $this->assertFalse(Config::get('enableRegister'));
    }

    public function testGetUsers(): void
    {
        $users = Config::getUsers();
        $this->assertCount(2, $users);
        $this->assertEquals('alice', $users[0]['username']);
    }

    public function testGetUserFound(): void
    {
        $user = Config::getUser('alice');
        $this->assertNotNull($user);
        $this->assertEquals('alice', $user['username']);
        $this->assertTrue($user['allow_cicd']);
    }

    public function testGetUserNotFound(): void
    {
        $this->assertNull(Config::getUser('nonexistent'));
    }

    public function testSetUsers(): void
    {
        Config::setUsers([['username' => 'newbie', 'password' => 'hash']]);
        $this->assertCount(1, Config::getUsers());
    }

    public function testGetRepositoriesPath(): void
    {
        $this->assertEquals('/tmp/test_repos', Config::getRepositoriesPath());
    }

    public function testGetExcludedFolders(): void
    {
        $this->assertEquals(['trash'], Config::getExcludedFolders());
    }

    public function testGetCacheFile(): void
    {
        $this->assertEquals('/tmp/test_cache', Config::getCacheFile());
    }

    public function testSavePersistsChanges(): void
    {
        Config::setUsers([['username' => 'saved', 'password' => 'hash']]);
        Config::save();

        $content = file_get_contents($this->configPath);
        $data = json_decode($content, true);
        $this->assertEquals('saved', $data['users'][0]['username']);
    }

    public function testReloadClearsCache(): void
    {
        Config::load();
        Config::reload();

        $ref = new \ReflectionClass(Config::class);
        $configProp = $ref->getProperty('config');
        $configProp->setAccessible(true);
        $this->assertNull($configProp->getValue());
    }

    public function testLoadThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::init('/nonexistent/config.json');
        Config::load();
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $badPath = $this->configPath . '.bad';
        file_put_contents($badPath, 'not json');
        $this->expectException(\RuntimeException::class);
        Config::init($badPath);
        Config::load();
        unlink($badPath);
    }
}
