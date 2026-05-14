<?php

namespace Tests\Integration;

use Lugit\Config;
use Lugit\RepoConfig;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group git-ssh
 */
class GitSshTest extends TestCase
{
    private static string $tmpDir;
    private static bool $sshAvailable = false;
    private static int $sshPort = 2222;
    private static string $sshUser = 'git';
    private static string $repoPath = '/home/git';

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = testTempDir() . '/ssh_test';

        self::$sshAvailable = self::checkSshAvailability();
    }

    protected function setUp(): void
    {
        if (!self::$sshAvailable) {
            $this->markTestSkipped(
                'SSH server not available for integration testing. ' .
                'To enable SSH tests: create user "git", set up sshd on port ' . self::$sshPort . ', ' .
                'configure authorized_keys with the SshWrapper.php forced command.'
            );
        }
    }

    private static function checkSshAvailability(): bool
    {
        if (getenv('SKIP_SSH_TESTS')) {
            return false;
        }

        $testFlag = testTempDir() . '/ssh_available.flag';
        if (file_exists($testFlag)) {
            return true;
        }

        $process = @proc_open(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=3 -p ' . self::$sshPort . ' ' . self::$sshUser . '@localhost "echo ok" 2>/dev/null',
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code === 0 && trim($output) === 'ok') {
            file_put_contents($testFlag, '1');
            return true;
        }

        return false;
    }

    public function testSshConnection(): void
    {
        $result = $this->sshCommand('echo connected');
        $this->assertStringContainsString('connected', $result['stdout']);
        $this->assertEquals(0, $result['code']);
    }

    public function testSshGitUploadPack(): void
    {
        $repoName = 'ssh-test-' . uniqid() . '.git';
        $repoFullPath = self::$repoPath . '/' . $repoName;

        if (!is_dir(dirname($repoFullPath))) {
            mkdir(dirname($repoFullPath), 0777, true);
        }
        exec('git init --bare ' . escapeshellarg($repoFullPath) . ' 2>/dev/null');
        $config = new RepoConfig(allowedUsers: ['testuser'], public: true);
        $config->save($repoFullPath);

        $result = $this->sshCommand('git-upload-pack ' . $repoName);
        $this->assertEquals(0, $result['code'], 'STDERR: ' . $result['stderr']);
        exec('rm -rf ' . escapeshellarg($repoFullPath));
    }

    public function testSshGitReceivePack(): void
    {
        $repoName = 'ssh-receive-' . uniqid() . '.git';
        $repoFullPath = self::$repoPath . '/' . $repoName;

        if (!is_dir(dirname($repoFullPath))) {
            mkdir(dirname($repoFullPath), 0777, true);
        }
        exec('git init --bare ' . escapeshellarg($repoFullPath) . ' 2>/dev/null');
        $config = new RepoConfig(allowedUsers: ['testuser'], public: false);
        $config->save($repoFullPath);

        $result = $this->sshCommand('git-receive-pack ' . $repoName);
        $this->assertEquals(0, $result['code'], 'STDERR: ' . $result['stderr']);
        exec('rm -rf ' . escapeshellarg($repoFullPath));
    }

    public function testSshInvalidCommandRejected(): void
    {
        $result = $this->sshCommand('git-clone something.git');
        $this->assertNotEquals(0, $result['code']);
    }

    public function testSshPathTraversalRejected(): void
    {
        $result = $this->sshCommand('git-upload-pack ../etc/passwd');
        $this->assertNotEquals(0, $result['code']);
    }

    public function testSshCloneRepository(): void
    {
        $repoName = 'ssh-clone-' . uniqid() . '.git';
        $repoFullPath = self::$repoPath . '/' . $repoName;
        $cloneDir = self::$tmpDir . '/ssh_clone_' . uniqid();

        exec('git init --bare ' . escapeshellarg($repoFullPath) . ' 2>/dev/null');
        $config = new RepoConfig(allowedUsers: ['testuser'], public: true);
        $config->save($repoFullPath);

        $result = $this->execute(
            'git clone ssh://' . self::$sshUser . '@localhost:' . self::$sshPort . $repoFullPath . ' ' . $cloneDir
        );

        $this->assertEquals(0, $result['code'], 'SSH clone failed: ' . $result['stderr']);

        exec('rm -rf ' . escapeshellarg($repoFullPath) . ' ' . escapeshellarg($cloneDir));
    }

    public function testSshPushWithKey(): void
    {
        $repoName = 'ssh-push-' . uniqid() . '.git';
        $repoFullPath = self::$repoPath . '/' . $repoName;

        exec('git init --bare ' . escapeshellarg($repoFullPath) . ' 2>/dev/null');
        $config = new RepoConfig(allowedUsers: ['testuser'], public: true);
        $config->save($repoFullPath);

        $localDir = self::$tmpDir . '/ssh_push_' . uniqid();
        exec('git init ' . $localDir . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($localDir) . ' config user.email "test@test.com"');
        exec('git -C ' . escapeshellarg($localDir) . ' config user.name "Test"');
        file_put_contents($localDir . '/file.txt', 'data');
        exec('git -C ' . escapeshellarg($localDir) . ' add .');
        exec('git -C ' . escapeshellarg($localDir) . ' commit -m "init" 2>/dev/null');

        $result = $this->execute(
            'git -C ' . escapeshellarg($localDir) . ' push ssh://' . self::$sshUser . '@localhost:' . self::$sshPort . $repoFullPath . ' master'
        );

        $this->assertEquals(0, $result['code'], 'SSH push failed: ' . $result['stderr']);

        exec('rm -rf ' . escapeshellarg($repoFullPath) . ' ' . escapeshellarg($localDir));
    }

    private function sshCommand(string $command): array
    {
        return $this->execute(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 -p ' . self::$sshPort . ' ' .
            self::$sshUser . '@localhost ' . escapeshellarg($command)
        );
    }

    private function execute(string $cmd): array
    {
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes);
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
}
