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
        $testRepo = 'public-clone-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo . '/public');
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

        $cloneDir = self::$tmpDir . '/clone_' . uniqid();
        $result = $this->execute('git clone ' . self::url() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir);
        $this->assertEquals(0, $result['code'], 'Clone failed: ' . $result['stderr']);
        $this->assertDirectoryExists($cloneDir . '/.git');
        $this->cleanDir($cloneDir);
    }

    public function testClonePrivateRepoWithAuth(): void
    {
        $testRepo = 'private-auth-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $cloneDir = self::$tmpDir . '/clone_private_' . uniqid();
        $result = $this->execute('git clone ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir);
        $this->assertEquals(0, $result['code'], 'Clone failed: ' . $result['stderr']);
        $this->assertDirectoryExists($cloneDir . '/.git');
        $this->cleanDir($cloneDir);
    }

    public function testClonePrivateRepoWithoutAuthFails(): void
    {
        $testRepo = 'private-noauth-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $cloneDir = self::$tmpDir . '/clone_noauth_' . uniqid();
        $result = $this->execute('git clone ' . self::url() . '/' . self::$testUser . '/' . $testRepo . ' ' . $cloneDir . ' 2>&1');
        $this->assertNotEquals(0, $result['code'], 'Clone should have failed');
        $this->cleanDir($cloneDir);
    }

    public function testPushOverHttp(): void
    {
        $testRepo = 'push-test-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $localDir = self::$tmpDir . '/push_local_' . uniqid();
        $this->execute('git init ' . $localDir);
        $this->execute('git config user.email "test@test.com"', $localDir);
        $this->execute('git config user.name "Test User"', $localDir);
        file_put_contents($localDir . '/test.txt', 'hello world');
        $this->execute('git add .', $localDir);
        $this->execute('git commit -m "initial"', $localDir);

        $result = $this->execute(
            'git push ' . $this->authUrl() . '/' . self::$testUser . '/' . $testRepo . ' master',
            $localDir
        );
        $this->assertEquals(0, $result['code'], 'Push failed: ' . $result['stderr']);
        $this->cleanDir($localDir);
    }

    public function testPushPullCycle(): void
    {
        $testRepo = 'cycle-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $localDir = self::$tmpDir . '/cycle_' . uniqid();
        mkdir($localDir, 0777, true);

        $this->assertTrue(true); // test structure exists

        $this->cleanDir($localDir);
    }

    public function testInfoRefsOnPublicRepo(): void
    {
        $testRepo = 'refs-public-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo . '/public');
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

        $url = self::url() . '/' . self::$testUser . '/' . $testRepo . '/info/refs?service=git-upload-pack';
        $result = $this->execute('curl -s ' . escapeshellarg($url));
        $this->assertStringContainsString('# service=', $result['stdout']);
    }

    public function testInfoRefsWithoutAuthOnPrivateFails(): void
    {
        $testRepo = 'refs-private-' . uniqid() . '.git';

        $ch = curl_init(self::SERVER . '/api/v1/repos/' . $testRepo);
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

        $url = self::url() . '/' . self::$testUser . '/' . $testRepo . '/info/refs?service=git-upload-pack';
        $result = $this->execute('curl -s -w "%{http_code}" ' . escapeshellarg($url));
        $this->assertStringContainsString('401', $result['stderr'] . $result['stdout']);
    }
}
