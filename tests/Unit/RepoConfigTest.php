<?php

namespace Tests\Unit;

use Lugit\RepoConfig;
use PHPUnit\Framework\TestCase;

class RepoConfigTest extends TestCase
{
    private string $repoPath;

    protected function setUp(): void
    {
        $this->repoPath = testTempDir() . '/testrepo.git';
        if (!is_dir($this->repoPath)) {
            mkdir($this->repoPath, 0777, true);
        }
        touch($this->repoPath . '/HEAD');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repoPath));
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    public function testDefaultConfig(): void
    {
        $config = new RepoConfig();
        $this->assertFalse($config->public);
        $this->assertEmpty($config->allowedUsers);
    }

    public function testLoadReturnsDefaultsForMissingFile(): void
    {
        $config = RepoConfig::load($this->repoPath);
        $this->assertFalse($config->public);
        $this->assertEmpty($config->allowedUsers);
    }

    public function testSaveAndLoad(): void
    {
        $config = new RepoConfig(
            allowedUsers: ['alice', 'bob'],
            public: true
        );
        $config->save($this->repoPath);

        $loaded = RepoConfig::load($this->repoPath);
        $this->assertTrue($loaded->public);
        $this->assertEquals(['alice', 'bob'], $loaded->allowedUsers);
    }

    public function testAddUser(): void
    {
        $config = new RepoConfig();
        $config->addUser('alice');
        $this->assertContains('alice', $config->allowedUsers);
    }

    public function testAddUserDuplicate(): void
    {
        $config = new RepoConfig(allowedUsers: ['alice']);
        $config->addUser('alice');
        $this->assertCount(1, $config->allowedUsers);
    }

    public function testRemoveUser(): void
    {
        $config = new RepoConfig(allowedUsers: ['alice', 'bob']);
        $config->removeUser('alice');
        $this->assertNotContains('alice', $config->allowedUsers);
        $this->assertContains('bob', $config->allowedUsers);
    }

    public function testRemoveNonExistentUserDoesNothing(): void
    {
        $config = new RepoConfig(allowedUsers: ['alice']);
        $config->removeUser('nonexistent');
        $this->assertCount(1, $config->allowedUsers);
    }

    public function testHasUser(): void
    {
        $config = new RepoConfig(allowedUsers: ['alice', 'bob']);
        $this->assertTrue($config->hasUser('alice'));
        $this->assertFalse($config->hasUser('charlie'));
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        file_put_contents($this->repoPath . '/lugit.json', 'not json');
        $this->expectException(\RuntimeException::class);
        RepoConfig::load($this->repoPath);
    }

    public function testPersistedConfigUpdatesCorrectly(): void
    {
        $config = new RepoConfig(allowedUsers: ['alice'], public: false);
        $config->save($this->repoPath);

        $config->addUser('bob');
        $config->public = true;
        $config->save($this->repoPath);

        $loaded = RepoConfig::load($this->repoPath);
        $this->assertTrue($loaded->public);
        $this->assertEquals(['alice', 'bob'], $loaded->allowedUsers);
    }
}
