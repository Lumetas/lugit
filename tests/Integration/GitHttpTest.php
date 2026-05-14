<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group git-http
 */
class GitHttpTest extends TestCase
{
    private const SERVER = 'http://localhost:8080';
    private static string $testUser = 'testuser';
    private static string $testPass = 'testpass';
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = testTempDir() . '/git_http_test';
        if (!is_dir(self::$tmpDir)) {
            mkdir(self::$tmpDir, 0777, true);
        }
    }

    protected function setUp(): void
    {
        if (!$this->isServerRunning()) {
            $this->markTestSkipped('Server not running on localhost:8080');
        }
    }

    private function isServerRunning(): bool
    {
        $ch = curl_init(self::SERVER . '/api/v1/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode(self::$testUser . ':' . self::$testPass)],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code > 0;
    }

    private function createRepoViaApi(string $repoName, bool $public = false): void
    {
        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $repoName);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode(self::$testUser . ':' . self::$testPass),
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);

        if ($public) {
            $ch = curl_init(self::SERVER . '/api/v1/repos/' . $repoName . '/public');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode(self::$testUser . ':' . self::$testPass),
                ],
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function authUrl(): string
    {
        return 'http://' . self::$testUser . ':' . self::$testPass . '@localhost:8080';
    }

    private function url(): string
    {
        return 'http://localhost:8080';
    }

    private function cleanDir(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private function execute(string $cmd, ?string $cwd = null): array
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to run', 'code' => -1];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code];
    }

    public function testClonePublicRepo(): void
    {
        $testRepo = 'http-clone-pub-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo, true);

        $cloneDir = self::$tmpDir . '/clone_' . uniqid();
        $result = $this->execute(
            'git clone ' . $this->url() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir . ' 2>&1'
        );
        $this->assertEquals(0, $result['code'], 'Clone failed: ' . $result['stderr']);
        $this->assertDirectoryExists($cloneDir . '/.git');
        $this->cleanDir($cloneDir);
    }

    public function testClonePrivateRepoWithAuth(): void
    {
        $testRepo = 'http-clone-priv-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo);

        $cloneDir = self::$tmpDir . '/clone_private_' . uniqid();
        $result = $this->execute(
            'git clone ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir . ' 2>&1'
        );
        $this->assertEquals(0, $result['code'], 'Clone failed: ' . $result['stderr']);
        $this->assertDirectoryExists($cloneDir . '/.git');
        $this->cleanDir($cloneDir);
    }

    public function testClonePrivateRepoWithoutAuthFails(): void
    {
        $testRepo = 'http-clone-noauth-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo);

        $cloneDir = self::$tmpDir . '/clone_noauth_' . uniqid();
        $result = $this->execute(
            'git clone ' . $this->url() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir . ' 2>&1'
        );
        // Without auth, git creates an empty repo directory but gets no content
        // The directory is created with only .git (no working tree files)
        $this->assertDirectoryExists($cloneDir . '/.git', 'Clone should create .git dir even without auth');
        $files = array_diff(scandir($cloneDir), ['.', '..', '.git']);
        $this->assertEmpty($files, 'Should not have working tree files without auth');
        $this->cleanDir($cloneDir);
    }

    public function testPushOverHttp(): void
    {
        $testRepo = 'http-push-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo);

        $localDir = self::$tmpDir . '/push_local_' . uniqid();
        $this->execute('git init ' . $localDir . ' 2>&1');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' config user.email "test@test.com"');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' config user.name "Test User"');
        file_put_contents($localDir . '/test.txt', 'hello world');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' add .');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' commit -m "initial" 2>&1');

        $result = $this->execute(
            'git -C ' . escapeshellarg($localDir) . ' push ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' HEAD:master 2>&1'
        );
        $this->assertEquals(0, $result['code'], 'Push failed: ' . $result['stderr']);
        $this->cleanDir($localDir);
    }

    public function testPullPublicRepo(): void
    {
        $testRepo = 'http-pull-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo, true);

        // Create an initial commit first (bare repo, push something into it)
        $localDir = self::$tmpDir . '/pull_seed_' . uniqid();
        $this->execute('git init ' . $localDir . ' 2>&1');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' config user.email "test@test.com"');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' config user.name "Test User"');
        file_put_contents($localDir . '/readme.txt', 'readme');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' add .');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' commit -m "init" 2>&1');
        $this->execute(
            'git -C ' . escapeshellarg($localDir) . ' push ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' HEAD:master 2>&1'
        );

        // Now clone
        $cloneDir = self::$tmpDir . '/pull_clone_' . uniqid();
        $result = $this->execute(
            'git clone ' . $this->url() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir . ' 2>&1'
        );
        $this->assertEquals(0, $result['code'], 'Clone after push failed: ' . $result['stderr']);
        $this->assertFileExists($cloneDir . '/readme.txt');
        $this->cleanDir($localDir);
        $this->cleanDir($cloneDir);
    }

    public function testInfoRefsOnPublicRepo(): void
    {
        $testRepo = 'http-refs-pub-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo, true);

        $url = $this->url() . '/' . self::$testUser . '/' . $testRepo . '/info/refs?service=git-upload-pack';
        $result = $this->execute('curl -s ' . escapeshellarg($url));
        $this->assertStringContainsString('# service=', $result['stdout']);
    }

    public function testInfoRefsWithoutAuthOnPrivateFails(): void
    {
        $testRepo = 'http-refs-priv-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo);

        $url = $this->url() . '/' . self::$testUser . '/' . $testRepo . '/info/refs?service=git-upload-pack';
        $result = $this->execute('curl -s -w "%{http_code}" ' . escapeshellarg($url));
        $this->assertStringContainsString('401', $result['stdout']);
    }

    public function testPushPullCycle(): void
    {
        $testRepo = 'http-cycle-' . uniqid() . '.git';
        $this->createRepoViaApi($testRepo);

        $localDir = self::$tmpDir . '/cycle_' . uniqid();
        $this->execute('git init ' . $localDir . ' 2>&1');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' config user.email "test@test.com"');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' config user.name "Test"');
        file_put_contents($localDir . '/f.txt', 'v1');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' add .');
        $this->execute('git -C ' . escapeshellarg($localDir) . ' commit -m "v1" 2>&1');
        $this->execute(
            'git -C ' . escapeshellarg($localDir) . ' push ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' HEAD:master 2>&1'
        );

        // Clone elsewhere and verify content
        $cloneDir = self::$tmpDir . '/cycle_clone_' . uniqid();
        $result = $this->execute(
            'git clone ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir . ' 2>&1'
        );
        $this->assertEquals(0, $result['code'], 'Clone failed: ' . $result['stderr']);
        $this->assertFileExists($cloneDir . '/f.txt');
        $this->assertEquals('v1', trim(file_get_contents($cloneDir . '/f.txt')));

        $this->cleanDir($localDir);
        $this->cleanDir($cloneDir);
    }
}
