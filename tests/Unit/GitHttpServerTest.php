<?php

namespace Tests\Unit;

use Lugit\Config;
use Lugit\GitHttpServer;
use Lugit\RepoCache;
use Lugit\RepoConfig;
use PHPUnit\Framework\TestCase;

class GitHttpServerTest extends TestCase
{
    private string $reposPath;
    private string $cacheFile;
    private array $sendErrorCalls = [];

    protected function setUp(): void
    {
        $this->reposPath = testTempReposDir();
        $this->cacheFile = testTempDir() . '/git_http_cache_' . bin2hex(random_bytes(4));
        initTestConfigFromArray($this->reposPath, ['cacheFile' => $this->cacheFile]);
        RepoCache::init($this->cacheFile);

        createTestBareRepo('testuser', 'public-repo.git');
        $config = new RepoConfig(allowedUsers: ['testuser'], public: true);
        $config->save($this->reposPath . '/testuser/public-repo.git');

        createTestBareRepo('testuser', 'private-repo.git');
        $config = new RepoConfig(allowedUsers: ['testuser'], public: false);
        $config->save($this->reposPath . '/testuser/private-repo.git');
    }

    protected function tearDown(): void
    {
        Config::reload();
        $_SERVER = array_filter($_SERVER, fn($k) => !in_array($k, ['HTTP_AUTHORIZATION', 'QUERY_STRING']), ARRAY_FILTER_USE_KEY);
        $this->sendErrorCalls = [];
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    private function createServer(): GitHttpServer
    {
        $server = $this->getMockBuilder(GitHttpServer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendError'])
            ->getMock();

        $server->method('sendError')->willReturnCallback(function ($code, $msg) {
            $this->sendErrorCalls[] = [$code, $msg];
            throw new \RuntimeException("sendError($code): $msg");
        });

        setPrivateProperty($server, 'basePath', $this->reposPath);
        setPrivateProperty($server, 'excludedFolders', []);
        setPrivateProperty($server, 'currentUser', ['username' => 'testuser']);

        return $server;
    }

    private function createUnauthenticatedServer(): GitHttpServer
    {
        $server = $this->getMockBuilder(GitHttpServer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendError'])
            ->getMock();

        $server->method('sendError')->willReturnCallback(function ($code, $msg) {
            $this->sendErrorCalls[] = [$code, $msg];
            throw new \RuntimeException("sendError($code): $msg");
        });

        setPrivateProperty($server, 'basePath', $this->reposPath);
        setPrivateProperty($server, 'excludedFolders', []);
        setPrivateProperty($server, 'currentUser', null);

        return $server;
    }

    public function testCheckRepoAndUserValid(): void
    {
        $server = $this->createServer();
        $result = $server->checkRepoAndUser('testuser', 'public-repo.git');
        $this->assertIsString($result);
        $this->assertStringEndsWith('testuser/public-repo.git', $result);
    }

    public function testCheckRepoAndUserInvalidUsername(): void
    {
        $server = $this->createServer();
        $this->expectException(\RuntimeException::class);
        $server->checkRepoAndUser('../etc', 'repo.git');
    }

    public function testCheckRepoAndUserNonExistentRepo(): void
    {
        $server = $this->createServer();
        $this->expectException(\RuntimeException::class);
        $server->checkRepoAndUser('testuser', 'nope.git');
    }

    public function testCheckRepoAndUserExcludedFolder(): void
    {
        $server = $this->createServer();
        setPrivateProperty($server, 'excludedFolders', ['testuser']);
        $this->expectException(\RuntimeException::class);
        $server->checkRepoAndUser('testuser', 'public-repo.git');
    }

    public function testHandleInfoRefsPublicRepoUploadPack(): void
    {
        $server = $this->createUnauthenticatedServer();
        $_GET['service'] = 'git-upload-pack';

        $repoPath = $this->reposPath . '/testuser/public-repo.git';
        ob_start();
        $server->handleInfoRefs($repoPath);
        $output = ob_get_clean();

        $this->assertStringContainsString('# service=', $output);
        $this->assertStringContainsString('0000', $output);
    }

    public function testHandleInfoRefsPrivateRepoWithoutAuth(): void
    {
        $server = $this->createUnauthenticatedServer();
        $_GET['service'] = 'git-upload-pack';

        $repoPath = $this->reposPath . '/testuser/private-repo.git';
        $this->expectException(\RuntimeException::class);
        $server->handleInfoRefs($repoPath);
    }

    public function testHandleInfoRefsWithAuthForPrivateRepo(): void
    {
        $server = $this->createServer();
        $_GET['service'] = 'git-upload-pack';

        $repoPath = $this->reposPath . '/testuser/private-repo.git';
        ob_start();
        $server->handleInfoRefs($repoPath);
        $output = ob_get_clean();

        $this->assertStringContainsString('# service=', $output);
    }

    public function testHandleInfoRefsInvalidService(): void
    {
        $server = $this->createServer();
        $_GET['service'] = 'invalid-service';

        $repoPath = $this->reposPath . '/testuser/public-repo.git';
        $this->expectException(\RuntimeException::class);
        $server->handleInfoRefs($repoPath);
    }

    public function testHandleUploadPackPublicRepoNoAuth(): void
    {
        $server = $this->createUnauthenticatedServer();
        $repoPath = $this->reposPath . '/testuser/public-repo.git';
        ob_start();
        $server->handleUploadPack($repoPath);
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testHandleUploadPackPrivateRepoWithoutAuth(): void
    {
        $server = $this->createUnauthenticatedServer();
        $repoPath = $this->reposPath . '/testuser/private-repo.git';
        $this->expectException(\RuntimeException::class);
        $server->handleUploadPack($repoPath);
    }

    public function testHandleUploadPackPrivateRepoWithAuth(): void
    {
        $server = $this->createServer();
        $repoPath = $this->reposPath . '/testuser/private-repo.git';
        ob_start();
        $server->handleUploadPack($repoPath);
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testHandleReceivePackWithoutAuth(): void
    {
        $server = $this->createUnauthenticatedServer();
        $repoPath = $this->reposPath . '/testuser/public-repo.git';
        $this->expectException(\RuntimeException::class);
        $server->handleReceivePack($repoPath);
    }

    public function testHandleReceivePackWithAuth(): void
    {
        $server = $this->createServer();
        $repoPath = $this->reposPath . '/testuser/public-repo.git';
        ob_start();
        $server->handleReceivePack($repoPath);
        $output = ob_get_clean();
        $this->assertIsString($output);
    }

    public function testAuthenticateWithValidCredentials(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:testpass');
        $server = $this->createServer();

        $authenticate = new \ReflectionMethod(GitHttpServer::class, 'authenticate');
        $authenticate->setAccessible(true);

        $result = $authenticate->invoke($server);
        $this->assertTrue($result);
    }

    public function testAuthenticateWithInvalidCredentials(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('testuser:wrongpass');
        $server = $this->createUnauthenticatedServer();

        $authenticate = new \ReflectionMethod(GitHttpServer::class, 'authenticate');
        $authenticate->setAccessible(true);

        $result = $authenticate->invoke($server);
        $this->assertFalse($result);
    }

    public function testAuthenticateWithoutHeader(): void
    {
        $server = $this->createUnauthenticatedServer();
        $authenticate = new \ReflectionMethod(GitHttpServer::class, 'authenticate');
        $authenticate->setAccessible(true);
        $result = $authenticate->invoke($server);
        $this->assertFalse($result);
    }
}
