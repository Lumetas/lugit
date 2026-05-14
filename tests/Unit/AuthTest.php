<?php

namespace Tests\Unit;

use Lugit\Auth;
use Lugit\Config;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    private string $password;

    protected function setUp(): void
    {
        $this->password = password_hash('secret', PASSWORD_ARGON2ID);
        $reposPath = testTempReposDir();
        $config = [
            'users' => [
                ['username' => 'alice', 'password' => $this->password, 'allow_cicd' => true],
            ],
            'repositoriesPath' => $reposPath,
        ];
        Config::reload();
        $ref = new \ReflectionClass(Config::class);
        $prop = $ref->getProperty('config');
        $prop->setAccessible(true);
        $prop->setValue($config);
        $pathProp = $ref->getProperty('configPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue(testTempDir() . '/auth_config_direct.json');
    }

    protected function tearDown(): void
    {
        Config::reload();
        $_SERVER = array_filter($_SERVER, fn($k) => !in_array($k, ['PHP_AUTH_USER', 'PHP_AUTH_PW', 'HTTP_AUTHORIZATION', 'HTTP_PROXY_AUTHORIZATION']), ARRAY_FILTER_USE_KEY);
    }

    public static function tearDownAfterClass(): void
    {
        cleanupTestDir();
    }

    public function testAuthenticateWithPhpAuth(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['PHP_AUTH_PW'] = 'secret';
        $user = Auth::authenticate();
        $this->assertNotNull($user);
        $this->assertEquals('alice', $user['username']);
    }

    public function testAuthenticateWithHttpAuthorization(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:secret');
        $user = Auth::authenticate();
        $this->assertNotNull($user);
        $this->assertEquals('alice', $user['username']);
    }

    public function testAuthenticateWithProxyAuth(): void
    {
        $_SERVER['HTTP_PROXY_AUTHORIZATION'] = 'Basic ' . base64_encode('alice:secret');
        $user = Auth::authenticate();
        $this->assertNotNull($user);
        $this->assertEquals('alice', $user['username']);
    }

    public function testAuthenticateReturnsNullOnWrongPassword(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['PHP_AUTH_PW'] = 'wrong';
        $this->assertNull(Auth::authenticate());
    }

    public function testAuthenticateReturnsNullOnUnknownUser(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'eve';
        $_SERVER['PHP_AUTH_PW'] = 'secret';
        $this->assertNull(Auth::authenticate());
    }

    public function testAuthenticateReturnsNullOnNoCredentials(): void
    {
        $this->assertNull(Auth::authenticate());
    }

    public function testAuthenticateWithInvalidBase64(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic not-base64!!!';
        $this->assertNull(Auth::authenticate());
    }

    public function testAuthenticateWithMalformedBasicHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('no-colon');
        $this->assertNull(Auth::authenticate());
    }

    public function testParseAuthFromUrl(): void
    {
        $result = Auth::parseAuthFromUrl('http://user:pass@example.com/repo');
        $this->assertNotNull($result);
        $this->assertEquals('user', $result['username']);
        $this->assertEquals('pass', $result['password']);
    }

    public function testParseAuthFromUrlWithoutAuth(): void
    {
        $this->assertNull(Auth::parseAuthFromUrl('http://example.com/repo'));
    }

    public function testParseAuthFromUrlWithSpecialChars(): void
    {
        $result = Auth::parseAuthFromUrl('http://user%40test:pa%24s@example.com');
        $this->assertEquals('user%40test', $result['username']);
        $this->assertEquals('pa%24s', $result['password']);
    }

    public function testRequireAuthReturnsUserOnValid(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['PHP_AUTH_PW'] = 'secret';
        $user = Auth::requireAuth();
        $this->assertEquals('alice', $user['username']);
    }

    public function testRequireAuthWithWrongPasswordReturnsNull(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'alice';
        $_SERVER['PHP_AUTH_PW'] = 'wrong';
        $user = Auth::authenticate();
        $this->assertNull($user);
    }
}
