<?php

namespace Tests\Unit;

use Lugit\Config;
use Lugit\GitApi;
use Lugit\RepoCache;
use Lugit\RepoConfig;
use Lugit\Utils;
use PHPUnit\Framework\TestCase;

class GitApiTest extends TestCase
{
    private string $reposPath;
    private string $cacheFile;
    private array $sendJsonCalls = [];

    protected function setUp(): void
    {
        $this->reposPath = testTempReposDir();
        $this->cacheFile = testTempDir() . '/gitapi_cache_' . bin2hex(random_bytes(4));
        initTestConfigFromArray($this->reposPath, [
            'cacheFile' => $this->cacheFile
        ]);
        RepoCache::init($this->cacheFile);
        $this->sendJsonCalls = [];
    }

    protected function tearDown(): void
    {
        Config::reload();
        $_SERVER = array_filter($_SERVER, fn($k) => !in_array('HTTP_AUTHORIZATION', [$k]), ARRAY_FILTER_USE_KEY);
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    private function createApi(): GitApi
    {
        $api = $this->getMockBuilder(GitApi::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendJson', 'sendError'])
            ->getMock();

        $api->method('sendError')->willReturnCallback(function (int $code, string $msg, array $extra = []) {
            throw new \RuntimeException("sendError($code): $msg");
        });

        $api->method('sendJson')->willReturnCallback(function (...$args) {
            $this->sendJsonCalls[] = $args;
        });

        setPrivateProperty($api, 'currentUser', ['username' => 'testuser', 'allow_cicd' => true]);
        setPrivateProperty($api, 'basePath', $this->reposPath);
        setPrivateProperty($api, 'excludedFolders', []);

        return $api;
    }

    private function createApiAs(string $username, bool $cicd = false): GitApi
    {
        $api = $this->getMockBuilder(GitApi::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendJson', 'sendError'])
            ->getMock();

        $api->method('sendError')->willReturnCallback(function (int $code, string $msg, array $extra = []) {
            throw new \RuntimeException("sendError($code): $msg");
        });

        $api->method('sendJson')->willReturnCallback(function (...$args) {
            $this->sendJsonCalls[] = $args;
        });

        setPrivateProperty($api, 'currentUser', ['username' => $username, 'allow_cicd' => $cicd]);
        setPrivateProperty($api, 'basePath', $this->reposPath);
        setPrivateProperty($api, 'excludedFolders', []);

        return $api;
    }

    private function givenRepo(string $owner, string $name, bool $public = false, array $users = []): void
    {
        $reposPath = testTempReposDir();
        $repoPath = $reposPath . '/' . $owner . '/' . $name;
        if (is_dir($repoPath)) {
            exec('rm -rf ' . escapeshellarg($repoPath));
        }
        $path = createTestBareRepo($owner, $name);
        $allowed = $users ?: [$owner];
        $config = new RepoConfig(allowedUsers: $allowed, public: $public);
        $config->save($path);
        RepoCache::addRepo($owner, $name, [
            'public' => $public,
            'allowedUsers' => $allowed
        ]);
    }

    // --- authenticate ---

    public function testAuthenticateSetsCurrentUser(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:testpass');
        $api = $this->getMockBuilder(GitApi::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendJson', 'sendError'])
            ->getMock();
        $api->method('sendError')->willReturnCallback(function () {
            throw new \RuntimeException('error');
        });
        $api->method('sendJson')->willReturnCallback(function (...$args) {
            $this->sendJsonCalls[] = $args;
        });
        setPrivateProperty($api, 'basePath', $this->reposPath);
        setPrivateProperty($api, 'excludedFolders', []);

        $authenticate = new \ReflectionMethod(GitApi::class, 'authenticate');
        $authenticate->setAccessible(true);
        $authenticate->invoke($api);

        $user = getPrivateProperty($api, 'currentUser');
        $this->assertNotNull($user);
        $this->assertEquals('testuser', $user['username']);
    }

    public function testAuthenticateFailsOnWrongPass(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:wrongpass');
        $api = $this->createApi();
        $authenticate = new \ReflectionMethod(GitApi::class, 'authenticate');
        $authenticate->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $authenticate->invoke($api);
    }

    // --- getCurrentUser ---

    public function testGetCurrentUserWithAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:testpass');
        $api = $this->createApi();
        $api->getCurrentUser();
        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals('testuser', $this->sendJsonCalls[0][0]['username']);
    }

    public function testGetCurrentUserWithoutAuth(): void
    {
        $api = $this->createApi();
        $api->getCurrentUser();
        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertNull($this->sendJsonCalls[0][0]['username']);
    }

    // --- listRepos ---

    public function testListRepos(): void
    {
        $this->givenRepo('testuser', 'myrepo.git');
        $this->givenRepo('testuser', 'other.git');
        $this->givenRepo('otheruser', 'foreign.git');

        $api = $this->createApi();
        $api->listRepos();

        $this->assertCount(1, $this->sendJsonCalls);
        $repos = $this->sendJsonCalls[0][0];
        $names = array_map(fn($r) => $r['name'], $repos);
        $this->assertContains('myrepo.git', $names);
        $this->assertContains('other.git', $names);
        $this->assertNotContains('foreign.git', $names);
    }

    public function testListReposEmptyForNewUser(): void
    {
        $api = $this->createApiAs('newuser');
        $api->listRepos();
        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEmpty($this->sendJsonCalls[0][0]);
    }

    // --- getRepo ---

    public function testGetRepoOwnedByUser(): void
    {
        $this->givenRepo('testuser', 'myrepo.git');
        $api = $this->createApi();
        $api->getRepo('myrepo.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals('myrepo.git', $this->sendJsonCalls[0][0]['name']);
    }

    public function testGetRepoNotFound(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->getRepo('nope.git');
    }

    public function testGetRepoSpecificUser(): void
    {
        $this->givenRepo('otheruser', 'shared.git', users: ['testuser']);
        $api = $this->createApi();
        $api->getRepo('otheruser/shared.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals('shared.git', $this->sendJsonCalls[0][0]['name']);
    }

    public function testGetRepoAccessDenied(): void
    {
        $this->givenRepo('otheruser', 'private.git', users: ['otheruser']);
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->getRepo('otheruser/private.git');
    }

    // --- createRepo ---

    public function testCreateRepoSuccess(): void
    {
        $api = $this->createApi();
        $api->createRepo('newrepo.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals(201, $this->sendJsonCalls[0][1] ?? 200);
        $this->assertEquals('newrepo.git', $this->sendJsonCalls[0][0]['name']);
        $this->assertFalse($this->sendJsonCalls[0][0]['public']);
        $this->assertTrue(Utils::isGitRepo($this->reposPath . '/testuser/newrepo.git'));
        $this->assertTrue(RepoCache::hasRepo('testuser', 'newrepo.git'));
    }

    public function testCreateRepoInvalidName(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->createRepo('..invalid');
    }

    public function testCreateRepoDuplicate(): void
    {
        $this->givenRepo('testuser', 'existing.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->createRepo('existing.git');
    }

    // --- deleteRepo ---

    public function testDeleteRepoSuccess(): void
    {
        $this->givenRepo('testuser', 'todelete.git');
        $api = $this->createApi();
        $api->deleteRepo('todelete.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertStringContainsString('todelete.git', $this->sendJsonCalls[0][0]['message']);
        $this->assertDirectoryDoesNotExist($this->reposPath . '/testuser/todelete.git');
    }

    public function testDeleteRepoNotFound(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->deleteRepo('nope.git');
    }

    // --- setVisibility ---

    public function testSetVisibilityPublic(): void
    {
        $this->givenRepo('testuser', 'myrepo.git');
        $api = $this->createApi();
        $api->setVisibility('myrepo.git', true);

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertTrue($this->sendJsonCalls[0][0]['public']);
        $repo = RepoCache::getRepo('testuser', 'myrepo.git');
        $this->assertTrue($repo['public']);
    }

    public function testSetVisibilityPrivate(): void
    {
        $this->givenRepo('testuser', 'myrepo.git', public: true);
        $api = $this->createApi();
        $api->setVisibility('myrepo.git', false);

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertFalse($this->sendJsonCalls[0][0]['public']);
        $repo = RepoCache::getRepo('testuser', 'myrepo.git');
        $this->assertFalse($repo['public']);
    }

    public function testSetVisibilityOnNonExistent(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->setVisibility('nope.git', true);
    }

    // --- addUser / removeUser / listUsers ---

    public function testAddUserToRepo(): void
    {
        $this->givenRepo('testuser', 'shared.git');
        $api = $this->createApi();
        $api->addUser('shared.git', 'bob');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertStringContainsString('bob', $this->sendJsonCalls[0][0]['message']);
        $repo = RepoCache::getRepo('testuser', 'shared.git');
        $this->assertContains('bob', $repo['allowedUsers']);
    }

    public function testAddUserAlreadyHasAccess(): void
    {
        $this->givenRepo('testuser', 'shared.git', users: ['testuser', 'bob']);
        $api = $this->createApi();
        $api->addUser('shared.git', 'bob');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertStringContainsString('already has access', $this->sendJsonCalls[0][0]['message']);
    }

    public function testAddUserNotOwner(): void
    {
        $this->givenRepo('otheruser', 'their.git', users: ['otheruser']);
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->addUser('otheruser/their.git', 'bob');
    }

    public function testRemoveUserFromRepo(): void
    {
        $this->givenRepo('testuser', 'shared.git', users: ['testuser', 'bob']);
        $api = $this->createApi();
        $api->removeUser('shared.git', 'bob');

        $this->assertCount(1, $this->sendJsonCalls);
        $repo = RepoCache::getRepo('testuser', 'shared.git');
        $this->assertNotContains('bob', $repo['allowedUsers']);
    }

    public function testRemoveUserNotInRepo(): void
    {
        $this->givenRepo('testuser', 'myrepo.git', users: ['testuser']);
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->removeUser('myrepo.git', 'bob');
    }

    public function testRemoveSelfNotAllowed(): void
    {
        $this->givenRepo('testuser', 'myrepo.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->removeUser('myrepo.git', 'testuser');
    }

    public function testListUsers(): void
    {
        $this->givenRepo('testuser', 'myrepo.git', users: ['testuser', 'bob']);
        $api = $this->createApi();
        $api->listUsers('myrepo.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals(['testuser', 'bob'], $this->sendJsonCalls[0][0]);
    }

    public function testListUsersPublicRepoNonMember(): void
    {
        $this->givenRepo('otheruser', 'public.git', public: true, users: ['otheruser']);
        $api = $this->createApi();
        $api->listUsers('otheruser/public.git');

        $this->assertCount(1, $this->sendJsonCalls);
    }

    // --- CI/CD ---

    public function testCicdListHooksEmpty(): void
    {
        $this->givenRepo('testuser', 'cicd.git');
        $api = $this->createApi();
        $api->cicdListHooks('cicd.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEmpty($this->sendJsonCalls[0][0]['hooks']);
    }

    public function testCicdListHooksWithHook(): void
    {
        $this->givenRepo('testuser', 'cicd.git');
        $hooksDir = $this->reposPath . '/testuser/cicd.git/lugit/hooks';
        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0777, true);
        }
        file_put_contents($hooksDir . '/main', '#!/bin/sh');
        chmod($hooksDir . '/main', 0755);

        $api = $this->createApi();
        $api->cicdListHooks('cicd.git');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals(['main'], $this->sendJsonCalls[0][0]['hooks']);
    }

    public function testCicdListHooksWithoutCicdPerm(): void
    {
        $api = $this->createApiAs('testuser', cicd: false);
        $this->givenRepo('testuser', 'nocicd.git');
        $this->expectException(\RuntimeException::class);
        $api->cicdListHooks('nocicd.git');
    }

    public function testCicdGetLogs(): void
    {
        $this->givenRepo('testuser', 'logs.git');
        $logsDir = $this->reposPath . '/testuser/logs.git/lugit/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }
        file_put_contents($logsDir . '/main', 'test log entry');

        $api = $this->createApi();
        $api->cicdGetLogs('logs.git', 'main');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals('test log entry', $this->sendJsonCalls[0][0]['logs']['main']);
    }

    public function testCicdGetLogsNonExistent(): void
    {
        $this->givenRepo('testuser', 'logs.git');
        $api = $this->createApi();
        $api->cicdGetLogs('logs.git', 'nonexistent');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals('', $this->sendJsonCalls[0][0]['logs']['nonexistent']);
    }

    public function testCicdCleanLogs(): void
    {
        $this->givenRepo('testuser', 'clean.git');
        $logsDir = $this->reposPath . '/testuser/clean.git/lugit/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }
        file_put_contents($logsDir . '/main', 'data');

        $api = $this->createApi();
        $api->cicdCleanLogs('clean.git', 'main');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertFileDoesNotExist($logsDir . '/main');
    }

    public function testCicdCleanLogsNonExistentBranch(): void
    {
        $this->givenRepo('testuser', 'clean.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->cicdCleanLogs('clean.git', 'nonexistent');
    }

    public function testCicdGetLogsRejectsPathTraversal(): void
    {
        $this->givenRepo('testuser', 'traversal.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->cicdGetLogs('traversal.git', '../etc/passwd');
    }

    // --- SSH Keys ---

    public function testListSshKeysEmpty(): void
    {
        $api = $this->createApi();
        $api->listSshKeys();
        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEmpty($this->sendJsonCalls[0][0]['keys']);
    }

    public function testAddSshKeyMissingField(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->addSshKey();
    }

    public function testDeleteSshKeyMissingField(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->deleteSshKey();
    }

    // --- register (error path: no php://input) ---

    public function testRegisterMissingFields(): void
    {
        Config::reload();
        Config::load();
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->register();
    }

    // --- login (error path: no php://input) ---

    public function testLoginWithoutData(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->login();
    }

    // --- changePassword (error path: no php://input) ---

    public function testChangePasswordWithoutData(): void
    {
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->changePassword();
    }

    // --- cicdSetHook (error path: no php://input) ---

    public function testCicdSetHookWithoutData(): void
    {
        $this->givenRepo('testuser', 'hook.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->cicdSetHook('hook.git', 'main');
    }

    // --- cicdDelHook ---

    public function testCicdDelHookNotFound(): void
    {
        $this->givenRepo('testuser', 'hookdel.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->cicdDelHook('hookdel.git', 'main');
    }

    public function testCicdDelHookSuccess(): void
    {
        $this->givenRepo('testuser', 'hookdel.git');
        $hooksDir = $this->reposPath . '/testuser/hookdel.git/lugit/hooks';
        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0777, true);
        }
        file_put_contents($hooksDir . '/main', '#!/bin/sh');
        $api = $this->createApi();
        $api->cicdDelHook('hookdel.git', 'main');

        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertFileDoesNotExist($hooksDir . '/main');
    }

    // --- cicdRunHook ---

    public function testCicdRunHookNotFound(): void
    {
        $this->givenRepo('testuser', 'norun.git');
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->cicdRunHook('norun.git', 'main');
    }

    // --- edge cases ---

    public function testCreateRepoWithDotsAndDashes(): void
    {
        $api = $this->createApi();
        $api->createRepo('my.cool-repo_1.git');
        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertEquals('my.cool-repo_1.git', $this->sendJsonCalls[0][0]['name']);
    }

    public function testIsPrivateRepoByDefault(): void
    {
        $api = $this->createApi();
        $api->createRepo('default.git');
        $this->assertCount(1, $this->sendJsonCalls);
        $this->assertFalse($this->sendJsonCalls[0][0]['public']);
    }

    public function testListUsersWithoutAccess(): void
    {
        $this->givenRepo('otheruser', 'secret.git', public: false, users: ['otheruser']);
        $api = $this->createApi();
        $this->expectException(\RuntimeException::class);
        $api->listUsers('otheruser/secret.git');
    }
}
