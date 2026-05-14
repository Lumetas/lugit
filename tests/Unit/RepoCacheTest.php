<?php

namespace Tests\Unit;

use Lugit\Config;
use Lugit\RepoCache;
use PHPUnit\Framework\TestCase;

class RepoCacheTest extends TestCase
{
    private string $cacheFile;
    private string $reposPath;

    protected function setUp(): void
    {
        $this->reposPath = testTempReposDir();
        $this->cacheFile = testTempDir() . '/test_repo_cache_' . bin2hex(random_bytes(4));
        initTestConfigFromArray($this->reposPath);
        RepoCache::init($this->cacheFile);
    }

    protected function tearDown(): void
    {
        Config::reload();
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    public function testLoadReturnsEmptyForMissingFile(): void
    {
        $cache = RepoCache::load();
        $this->assertIsArray($cache);
        $this->assertEmpty($cache);
    }

    public function testAddAndHasRepo(): void
    {
        RepoCache::addRepo('alice', 'myrepo', [
            'public' => false,
            'allowedUsers' => ['alice']
        ]);

        $this->assertTrue(RepoCache::hasRepo('alice', 'myrepo'));
        $this->assertFalse(RepoCache::hasRepo('bob', 'myrepo'));
    }

    public function testGetRepo(): void
    {
        RepoCache::addRepo('alice', 'myrepo', [
            'public' => true,
            'allowedUsers' => ['alice', 'bob']
        ]);

        $repo = RepoCache::getRepo('alice', 'myrepo');
        $this->assertNotNull($repo);
        $this->assertTrue($repo['public']);
        $this->assertEquals(['alice', 'bob'], $repo['allowedUsers']);
    }

    public function testGetRepoReturnsNullForMissing(): void
    {
        $this->assertNull(RepoCache::getRepo('nobody', 'nope'));
    }

    public function testUpdateRepo(): void
    {
        RepoCache::addRepo('alice', 'myrepo', [
            'public' => false,
            'allowedUsers' => ['alice']
        ]);

        RepoCache::updateRepo('alice', 'myrepo', [
            'public' => true
        ]);

        $repo = RepoCache::getRepo('alice', 'myrepo');
        $this->assertTrue($repo['public']);
        $this->assertEquals(['alice'], $repo['allowedUsers']);
    }

    public function testRemoveRepo(): void
    {
        RepoCache::addRepo('alice', 'myrepo', [
            'public' => false,
            'allowedUsers' => ['alice']
        ]);

        RepoCache::removeRepo('alice', 'myrepo');
        $this->assertFalse(RepoCache::hasRepo('alice', 'myrepo'));
    }

    public function testRemoveRepoCleansEmptyUser(): void
    {
        RepoCache::addRepo('alice', 'onlyrepo', [
            'public' => false,
            'allowedUsers' => ['alice']
        ]);

        RepoCache::removeRepo('alice', 'onlyrepo');

        $cache = RepoCache::getAll();
        $this->assertArrayNotHasKey('alice', $cache);
    }

    public function testRemoveNonExistentRepoDoesNothing(): void
    {
        RepoCache::addRepo('alice', 'realrepo', [
            'public' => false,
            'allowedUsers' => ['alice']
        ]);

        RepoCache::removeRepo('alice', 'nonexistent');
        $this->assertTrue(RepoCache::hasRepo('alice', 'realrepo'));
    }

    public function testGetUserRepos(): void
    {
        RepoCache::addRepo('alice', 'repo1', ['public' => false, 'allowedUsers' => ['alice']]);
        RepoCache::addRepo('alice', 'repo2', ['public' => true, 'allowedUsers' => ['alice']]);

        $repos = RepoCache::getUserRepos('alice');
        $this->assertCount(2, $repos);
        $this->assertArrayHasKey('repo1', $repos);
        $this->assertArrayHasKey('repo2', $repos);
    }

    public function testGetUserReposEmptyForUnknownUser(): void
    {
        $this->assertEmpty(RepoCache::getUserRepos('nobody'));
    }

    public function testGetAll(): void
    {
        RepoCache::addRepo('alice', 'repo1', ['public' => false, 'allowedUsers' => ['alice']]);
        $all = RepoCache::getAll();
        $this->assertArrayHasKey('alice', $all);
    }

    public function testSavePersistsToFile(): void
    {
        RepoCache::addRepo('alice', 'persisted', [
            'public' => true,
            'allowedUsers' => ['alice']
        ]);

        $this->assertFileExists($this->cacheFile);

        RepoCache::init($this->cacheFile . '.fresh');
        RepoCache::init($this->cacheFile);

        $this->assertTrue(RepoCache::hasRepo('alice', 'persisted'));
    }

    public function testUpdateNonExistentDoesNothing(): void
    {
        RepoCache::updateRepo('nobody', 'nope', ['public' => true]);
        $this->assertEmpty(RepoCache::getAll());
    }

    public function testAddRepoMultipleUsers(): void
    {
        RepoCache::addRepo('alice', 'shared', [
            'public' => false,
            'allowedUsers' => ['alice', 'bob']
        ]);

        $repo = RepoCache::getRepo('alice', 'shared');
        $this->assertCount(2, $repo['allowedUsers']);
    }
}
