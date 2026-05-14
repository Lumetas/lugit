<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class ApiTest extends TestCase
{
    private const BASE_URL = 'http://localhost:8080/api/v1';
    private static string $testUser = 'testuser';
    private static string $testPass = 'testpass';

    private function url(string $path): string
    {
        return self::BASE_URL . $path;
    }

    private function authHeader(): string
    {
        return 'Authorization: Basic ' . base64_encode(self::$testUser . ':' . self::$testPass);
    }

    private function get(string $path, array $extraHeaders = []): array
    {
        return $this->request('GET', $path, null, $extraHeaders);
    }

    private function post(string $path, ?array $data = null, array $extraHeaders = []): array
    {
        return $this->request('POST', $path, $data, $extraHeaders);
    }

    private function put(string $path, ?array $data = null, array $extraHeaders = []): array
    {
        return $this->request('PUT', $path, $data, $extraHeaders);
    }

    private function delete(string $path, array $extraHeaders = []): array
    {
        return $this->request('DELETE', $path, null, $extraHeaders);
    }

    private function request(string $method, string $path, ?array $data = null, array $extraHeaders = []): array
    {
        $headers = [$this->authHeader()];

        $ch = curl_init($this->url($path));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($data !== null) {
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($extraHeaders)) {
            $headers = array_merge($headers, $extraHeaders);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = $info['header_size'];
        $responseHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        return [
            'code' => $info['http_code'],
            'headers' => $responseHeaders,
            'body' => json_decode($body, true),
            'raw' => $body,
        ];
    }

    private function isServerRunning(): bool
    {
        $ch = curl_init(self::BASE_URL . '/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => [$this->authHeader()],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code > 0;
    }

    protected function setUp(): void
    {
        if (!$this->isServerRunning()) {
            $this->markTestSkipped('Server not running on localhost:8080. Start with: php -S localhost:8080 -t public/');
        }
    }

    // --- User endpoints ---

    public function testGetCurrentUser(): void
    {
        $res = $this->get('/user');
        $this->assertEquals(200, $res['code']);
        $this->assertEquals(self::$testUser, $res['body']['username']);
    }

    public function testGetCurrentUserWithoutAuth(): void
    {
        $res = $this->request('GET', '/user', null, []);
        $this->assertEquals(200, $res['code']);
        $this->assertNull($res['body']['username']);
    }

    // --- Login ---

    public function testLogin(): void
    {
        $res = $this->post('/login', [
            'username' => self::$testUser,
            'password' => self::$testPass,
        ], []);
        $this->assertEquals(200, $res['code']);
        $this->assertEquals(self::$testUser, $res['body']['username']);
    }

    public function testLoginInvalidCredentials(): void
    {
        $res = $this->post('/login', [
            'username' => self::$testUser,
            'password' => 'wrongpass',
        ], []);
        $this->assertEquals(401, $res['code']);
    }

    // --- Register ---

    public function testRegisterNewUser(): void
    {
        $res = $this->post('/register', [
            'username' => 'newbie',
            'password' => 'newpass',
        ], []);
        $this->assertEquals(201, $res['code']);
        $this->assertEquals('User registered successfully', $res['body']['message']);
    }

    public function testRegisterDuplicateUser(): void
    {
        $res = $this->post('/register', [
            'username' => self::$testUser,
            'password' => 'testpass',
        ], []);
        $this->assertEquals(409, $res['code']);
    }

    // --- Repo CRUD ---

    public function testCreateRepo(): void
    {
        $res = $this->post('/repos/integration-test.git');
        $this->assertEquals(201, $res['code']);
        $this->assertEquals('integration-test.git', $res['body']['name']);
        $this->assertEquals(self::$testUser, $res['body']['username']);
    }

    public function testCreateDuplicateRepo(): void
    {
        $this->post('/repos/dup.git'); // create first
        $res = $this->post('/repos/dup.git'); // try duplicate
        $this->assertEquals(409, $res['code']);
    }

    public function testListRepos(): void
    {
        $res = $this->get('/repos');
        $this->assertEquals(200, $res['code']);
        $this->assertIsArray($res['body']);
    }

    public function testGetRepo(): void
    {
        $this->post('/repos/gettest.git');
        $res = $this->get('/repos/gettest.git');
        $this->assertEquals(200, $res['code']);
        $this->assertEquals('gettest.git', $res['body']['name']);
    }

    public function testGetRepoNotFound(): void
    {
        $res = $this->get('/repos/no-such-repo.git');
        $this->assertEquals(404, $res['code']);
    }

    public function testDeleteRepo(): void
    {
        $this->post('/repos/deltest.git');
        $res = $this->delete('/repos/deltest.git');
        $this->assertEquals(200, $res['code']);
    }

    // --- Visibility ---

    public function testSetPublic(): void
    {
        $this->post('/repos/pubtest.git');
        $res = $this->put('/repos/pubtest.git/public');
        $this->assertEquals(200, $res['code']);
        $this->assertTrue($res['body']['public']);
    }

    public function testSetPrivate(): void
    {
        $this->post('/repos/privtest.git');
        $this->put('/repos/privtest.git/public');
        $res = $this->put('/repos/privtest.git/private');
        $this->assertEquals(200, $res['code']);
        $this->assertFalse($res['body']['public']);
    }

    // --- User management ---

    public function testAddUserToRepo(): void
    {
        $this->post('/repos/useradd.git');
        $res = $this->post('/repos/useradd.git/users/otheruser');
        $this->assertEquals(200, $res['code']);
        $this->assertStringContainsString('otheruser', $res['body']['message']);
    }

    public function testListUsersInRepo(): void
    {
        $this->post('/repos/userlist.git');
        $res = $this->get('/repos/userlist.git/users');
        $this->assertEquals(200, $res['code']);
        $this->assertIsArray($res['body']);
    }

    public function testRemoveUserFromRepo(): void
    {
        $this->post('/repos/userrm.git');
        $this->post('/repos/userrm.git/users/otheruser');
        $res = $this->delete('/repos/userrm.git/users/otheruser');
        $this->assertEquals(200, $res['code']);
    }

    // --- SSH Keys ---

    public function testListSshKeys(): void
    {
        $res = $this->get('/ssh/keys');
        $this->assertEquals(200, $res['code']);
        $this->assertArrayHasKey('keys', $res['body']);
    }

    public function testAddSshKeyInvalid(): void
    {
        $res = $this->post('/ssh/keys', ['key' => 'invalid key format']);
        $this->assertEquals(400, $res['code']);
    }

    // --- Change password (via API) ---

    public function testChangePasswordSuccess(): void
    {
        $res = $this->post('/changepass', [
            'username' => self::$testUser,
            'password' => self::$testPass,
            'newPassword' => 'newtestpass',
        ], []);
        $this->assertEquals(200, $res['code']);
    }

    // --- CI/CD ---

    public function testCicdListHooks(): void
    {
        $this->post('/repos/cicdtest.git');
        $res = $this->get('/repos/cicdtest.git/cicd');
        $this->assertEquals(200, $res['code']);
        $this->assertArrayHasKey('hooks', $res['body']);
    }
}
