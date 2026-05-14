<?php

namespace Tests\Unit;

use Lugit\Config;
use Lugit\GitCommandParser;
use Lugit\RepoConfig;
use PHPUnit\Framework\TestCase;

class GitCommandParserTest extends TestCase
{
    private string $reposPath;

    protected function setUp(): void
    {
        $this->reposPath = testTempReposDir();
        initTestConfigFromArray($this->reposPath);

        // GitCommandParser strips .git from the command path and looks for the repo
        // at {basePath}/{stripped-path}. So SSH command "git-upload-pack valid-repo.git"
        // looks for {basePath}/valid-repo (without .git suffix on disk)
        $this->makeRepo('valid-repo', ['testuser']);
        $this->makeRepo('testuser/valid-repo-prefixed', ['testuser']);
    }

    protected function tearDown(): void
    {
        Config::reload();
    }

    private function makeRepo(string $relativePath, array $users): void
    {
        $fullPath = $this->reposPath . '/' . $relativePath;
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        exec('git init --bare ' . escapeshellarg($fullPath) . ' 2>/dev/null');
        (new RepoConfig(allowedUsers: $users, public: false))->save($fullPath);
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    public function testConstructWithValidUploadPack(): void
    {
        $parser = new GitCommandParser(
            'git-upload-pack valid-repo.git',
            $this->reposPath,
            'testuser'
        );
        $parsed = $parser->getParsedCommand();
        $this->assertEquals('git-upload-pack', $parsed['command']);
        $this->assertEquals($this->reposPath . '/valid-repo', $parsed['repo']);
    }

    public function testConstructWithReceivePack(): void
    {
        $parser = new GitCommandParser(
            'git-receive-pack valid-repo.git',
            $this->reposPath,
            'testuser'
        );
        $parsed = $parser->getParsedCommand();
        $this->assertEquals('git-receive-pack', $parsed['command']);
    }

    public function testConstructWithUploadArchive(): void
    {
        $parser = new GitCommandParser(
            'git-upload-archive valid-repo.git',
            $this->reposPath,
            'testuser'
        );
        $parsed = $parser->getParsedCommand();
        $this->assertEquals('git-upload-archive', $parsed['command']);
    }

    public function testConstructWithUserPrefixedRepo(): void
    {
        $parser = new GitCommandParser(
            'git-upload-pack testuser/valid-repo-prefixed.git',
            $this->reposPath,
            'testuser'
        );
        $parsed = $parser->getParsedCommand();
        $this->assertEquals($this->reposPath . '/testuser/valid-repo-prefixed', $parsed['repo']);
    }

    public function testGetParsedCommandReturnsFullArray(): void
    {
        $parser = new GitCommandParser(
            'git-upload-pack valid-repo.git',
            $this->reposPath,
            'testuser'
        );
        $parsed = $parser->getParsedCommand();
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('command', $parsed);
        $this->assertArrayHasKey('repo', $parsed);
        $this->assertArrayHasKey('safe_repo', $parsed);
    }

    public function testExecuteReturnsFalseForMissingRepo(): void
    {
        $reflection = new \ReflectionClass(GitCommandParser::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $execute = $reflection->getMethod('execute');
        $execute->setAccessible(true);

        $result = $execute->invoke($instance, [
            'command' => 'git-upload-pack',
            'repo' => '/nonexistent/path',
            'safe_repo' => '/nonexistent/path',
        ]);
        $this->assertFalse($result);
    }

    public function testCheckCommandReturnsFalseWhenParsed(): void
    {
        $parser = new GitCommandParser(
            'git-upload-pack valid-repo.git',
            $this->reposPath,
            'testuser'
        );
        $this->assertFalse($parser->checkCommand());
    }

    private function parseViaReflection(string $command): ?array
    {
        $reflection = new \ReflectionClass(GitCommandParser::class);
        $method = $reflection->getMethod('parse');
        $method->setAccessible(true);
        return $method->invoke($reflection->newInstanceWithoutConstructor(), $command);
    }

    public function testParseRejectsInvalidCommand(): void
    {
        $this->assertNull($this->parseViaReflection('git-clone /repo.git'));
    }

    public function testParseRejectsEmptyRest(): void
    {
        $this->assertNull($this->parseViaReflection('git-upload-pack'));
    }

    public function testParseRejectsPathTraversal(): void
    {
        $this->assertNull($this->parseViaReflection('git-upload-pack ../etc/passwd'));
    }

    public function testParseRejectsDotPrefix(): void
    {
        $this->assertNull($this->parseViaReflection('git-upload-pack .hidden.git'));
    }

    public function testParseRejectsInvalidChars(): void
    {
        $this->assertNull($this->parseViaReflection('git-upload-pack $(rm -rf /).git'));
    }

    public function testParseHandlesQuotedPath(): void
    {
        $parsed = $this->parseViaReflection("git-upload-pack '/path/to/repo.git'");
        $this->assertNotNull($parsed);
        $this->assertEquals('/path/to/repo.git', $parsed['repo']);
    }

    public function testParseAcceptsValidPath(): void
    {
        $parsed = $this->parseViaReflection('git-upload-pack valid.repo_name.git');
        $this->assertNotNull($parsed);
        $this->assertEquals('valid.repo_name.git', $parsed['repo']);
    }
}
