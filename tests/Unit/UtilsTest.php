<?php

namespace Tests\Unit;

use Lugit\Config;
use Lugit\Utils;
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    private string $reposPath;

    protected function setUp(): void
    {
        $this->reposPath = testTempReposDir();
        initTestConfigFromArray($this->reposPath);
    }

    protected function tearDown(): void
    {
        Config::reload();
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    public function testIsValidRepoName(): void
    {
        $this->assertTrue(Utils::isValidRepoName('my-repo'));
        $this->assertTrue(Utils::isValidRepoName('my.repo'));
        $this->assertTrue(Utils::isValidRepoName('my_repo'));
        $this->assertTrue(Utils::isValidRepoName('MyRepo123'));
        $this->assertFalse(Utils::isValidRepoName(''));
        $this->assertFalse(Utils::isValidRepoName('../etc'));
        $this->assertFalse(Utils::isValidRepoName('.hidden'));
        $this->assertFalse(Utils::isValidRepoName('a b'));
        $this->assertFalse(Utils::isValidRepoName('/abs'));
    }

    public function testIsValidUsername(): void
    {
        $this->assertTrue(Utils::isValidUsername('test.user'));
        $this->assertTrue(Utils::isValidUsername('test-user'));
        $this->assertFalse(Utils::isValidUsername('../etc'));
        $this->assertFalse(Utils::isValidUsername('.hidden'));
    }

    public function testPktLine(): void
    {
        $result = Utils::pktLine('hello');
        $this->assertEquals('0009hello', $result);
    }

    public function testPktLineEmpty(): void
    {
        $result = Utils::pktLine('');
        $this->assertEquals('0004', $result);
    }

    public function testParsePktLine(): void
    {
        $result = Utils::parsePktLine('0009hello');
        $this->assertEquals('hello', $result);
    }

    public function testParsePktLineTooShort(): void
    {
        $this->assertEquals('', Utils::parsePktLine('ab'));
    }

    public function testParsePktLineEmpty(): void
    {
        $result = Utils::parsePktLine('0004');
        $this->assertEquals('', $result);
    }

    public function testPktLineRoundtrip(): void
    {
        $original = 'test data here';
        $encoded = Utils::pktLine($original);
        $decoded = Utils::parsePktLine($encoded);
        $this->assertEquals($original, $decoded);
    }

    public function testIsGitRepoOnNonExistent(): void
    {
        $this->assertFalse(Utils::isGitRepo('/nonexistent/path'));
    }

    public function testIsGitRepoOnValidBareRepo(): void
    {
        $repoPath = $this->reposPath . '/testuser/valid.git';
        if (!is_dir(dirname($repoPath))) {
            mkdir(dirname($repoPath), 0777, true);
        }
        exec('git init --bare ' . escapeshellarg($repoPath) . ' 2>/dev/null');
        $this->assertTrue(Utils::isGitRepo($repoPath));
    }

    public function testIsGitRepoOnRegularDir(): void
    {
        $dir = $this->reposPath . '/not-a-repo';
        mkdir($dir, 0777, true);
        $this->assertFalse(Utils::isGitRepo($dir));
    }

    public function testCreateBareRepo(): void
    {
        $path = $this->reposPath . '/creator/newrepo.git';
        Utils::createBareRepo($path);
        $this->assertTrue(is_dir($path . '/objects'));
        $this->assertTrue(is_dir($path . '/refs'));
        $this->assertFileExists($path . '/HEAD');
    }

    public function testCreateBareRepoInNonexistentParent(): void
    {
        $this->expectException(\RuntimeException::class);
        Utils::createBareRepo('/dev/null');
    }

    public function testDeleteRepo(): void
    {
        $path = $this->reposPath . '/deleteme/test.git';
        Utils::createBareRepo($path);
        $this->assertDirectoryExists($path);
        Utils::deleteRepo($path);
        $this->assertDirectoryDoesNotExist($path);
    }

    public function testDeleteRepoThrowsOnMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        Utils::deleteRepo('/nonexistent');
    }

    public function testListRepositoriesEmpty(): void
    {
        $this->assertIsArray(Utils::listRepositories());
    }

    public function testListRepositoriesWithRepos(): void
    {
        createTestBareRepo('testuser', 'repo1.git');
        createTestBareRepo('testuser', 'repo2.git');
        $list = Utils::listRepositories();
        $this->assertContains('testuser/repo1.git', $list);
        $this->assertContains('testuser/repo2.git', $list);
    }

    public function testFindRepoPathFound(): void
    {
        createTestBareRepo('testuser', 'findme.git');
        $result = Utils::findRepoPath('testuser', 'findme.git');
        $this->assertNotNull($result);
        $this->assertStringEndsWith('testuser/findme.git', $result);
    }

    public function testFindRepoPathNotFound(): void
    {
        $this->assertNull(Utils::findRepoPath('testuser', 'nonexistent.git'));
    }

    public function testGetRepoPathFound(): void
    {
        createTestBareRepo('testuser', 'pathfind.git');
        $result = Utils::getRepoPath('testuser', 'pathfind.git');
        $this->assertStringEndsWith('testuser/pathfind.git', $result);
    }

    public function testGetRepoPathNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        Utils::getRepoPath('testuser', 'nope.git');
    }

    public function testRunGit(): void
    {
        $result = Utils::runGit('echo hello', '/tmp');
        $this->assertEquals(0, $result['exitCode']);
        $this->assertStringContainsString('hello', $result['stdout']);
    }

    public function testRunGitFailsOnBadCommand(): void
    {
        $result = Utils::runGit('nonexistent_command_xyz 2>&1; exit 1', '/tmp');
        $this->assertNotEquals(0, $result['exitCode']);
    }
}
