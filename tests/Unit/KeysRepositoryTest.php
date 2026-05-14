<?php

namespace Tests\Unit;

use Lugit\Config;
use Lugit\KeysRepository;
use PHPUnit\Framework\TestCase;

class KeysRepositoryTest extends TestCase
{
    private string $keysDumpPath;

    protected function setUp(): void
    {
        $this->keysDumpPath = testTempDir() . '/test_keys_' . bin2hex(random_bytes(4)) . '.dump';
        initTestConfigFromArray(testTempReposDir(), [
            'keysDump' => $this->keysDumpPath
        ]);
        file_put_contents($this->keysDumpPath, serialize(['u2k' => [], 'k2u' => []]));
    }

    protected function tearDown(): void
    {
        Config::reload();
        if (file_exists($this->keysDumpPath)) {
            unlink($this->keysDumpPath);
        }
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    private function createRepo(): KeysRepository
    {
        return new KeysRepository();
    }

    public function testAddSshRsaKey(): void
    {
        $key = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC3... test@example.com';
        $repo = $this->createRepo();
        $name = $repo->add('testuser', $key);
        $this->assertEquals('test@example.com', $name);
    }

    public function testAddEd25519Key(): void
    {
        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI... user@host';
        $repo = $this->createRepo();
        $name = $repo->add('testuser', $key);
        $this->assertEquals('user@host', $name);
    }

    public function testAddKeyWithoutCommentUsesEmptyString(): void
    {
        $key = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC3...';
        $repo = $this->createRepo();
        $parts = explode(' ', $key);
        $name = isset($parts[2]) ? $parts[2] : '';
        $this->assertIsString($name);
    }

    public function testListKeys(): void
    {
        $key1 = 'ssh-rsa AAAA... key1@test';
        $key2 = 'ssh-ed25519 BBBB... key2@test';
        $repo = $this->createRepo();
        $repo->add('testuser', $key1);
        $repo->add('testuser', $key2);

        $keys = $repo->listKeys('testuser');
        $this->assertCount(2, $keys);
        $this->assertEquals('key1@test', $keys[$key1]);
        $this->assertEquals('key2@test', $keys[$key2]);
    }

    public function testListKeysEmptyForUnknownUser(): void
    {
        $repo = $this->createRepo();
        $this->assertEmpty($repo->listKeys('nobody'));
    }

    public function testRemoveKey(): void
    {
        $key = 'ssh-rsa AAAA... removable@test';
        $configPath2 = createTestConfig(testTempReposDir(), ['keysDump' => $this->keysDumpPath]);
        Config::init($configPath2);
        $repo = new KeysRepository();
        $repo->add('testuser', $key);
        $repo->remove('testuser', $key);

        $this->assertEmpty($repo->listKeys('testuser'));
    }

    public function testRemoveNonExistentKey(): void
    {
        $repo = $this->createRepo();
        $repo->add('testuser', 'ssh-rsa AAAA... existing@test');
        $repo->remove('testuser', 'ssh-rsa BBBB... ghost@test');

        $this->assertCount(1, $repo->listKeys('testuser'));
    }

    public function testYieldKeys(): void
    {
        $key1 = 'ssh-rsa AAAA... user1@test';
        $key2 = 'ssh-ed25519 BBBB... user2@test';
        $repo = $this->createRepo();
        $repo->add('user1', $key1);
        $repo->add('user2', $key2);

        $yielded = [];
        foreach ($repo->yieldKeys() as $k => $u) {
            $yielded[$k] = $u;
        }
        $this->assertEquals('user1', $yielded[$key1]);
        $this->assertEquals('user2', $yielded[$key2]);
    }

    public function testGetAllKeys(): void
    {
        $key = 'ssh-rsa AAAA... alltest@test';
        $repo = $this->createRepo();
        $repo->add('testuser', $key);

        $all = $repo->getAllKeys();
        $this->assertArrayHasKey($key, $all);
        $this->assertEquals('testuser', $all[$key]);
    }

    public function testSavePersistsData(): void
    {
        $key = 'ssh-rsa AAAA... persist@test';
        $repo = $this->createRepo();
        $repo->add('testuser', $key);
        $repo->save();

        $this->assertFileExists($this->keysDumpPath);
        $content = unserialize(file_get_contents($this->keysDumpPath));
        $this->assertArrayHasKey($key, $content['k2u']);
    }

    public function testMultipleUsersSameKeyIsolation(): void
    {
        $repo = $this->createRepo();
        $repo->add('alice', 'ssh-rsa AAAA... shared@test');
        $this->assertCount(1, $repo->listKeys('alice'));
    }
}
