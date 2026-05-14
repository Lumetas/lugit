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
    private static string $otherUser = 'otheruser';

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
        $body = substr($response, $headerSize);

        return [
            'code' => $info['http_code'],
            'body' => json_decode($body, true),
            'raw' => $body,
        ];
    }

    private function requestWithoutAuth(string $method, string $path, ?array $data = null): array
    {
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
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = $info['header_size'];
        $body = substr($response, $headerSize);

        return [
            'code' => $info['http_code'],
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
        $res = $this->requestWithoutAuth('GET', '/user');
        $this->assertEquals(401, $res['code']);
    }

    // --- Login ---

    public function testLogin(): void
    {
        $res = $this->post('/login', [
            'username' => self::$testUser,
            'password' => self::$testPass,
        ]);
        $this->assertEquals(200, $res['code']);
        $this->assertEquals(self::$testUser, $res['body']['username']);
    }

    public function testLoginInvalidCredentials(): void
    {
        $res = $this->post('/login', [
            'username' => self::$testUser,
            'password' => 'wrongpass',
        ]);
        $this->assertEquals(401, $res['code']);
    }

    // --- Register ---

    public function testRegisterNewUser(): void
    {
        $res = $this->post('/register', [
            'username' => 'reg-' . uniqid(),
            'password' => 'regpass',
        ]);
        $this->assertEquals(201, $res['code']);
    }

    public function testRegisterDuplicateUser(): void
    {
        $res = $this->post('/register', [
            'username' => self::$testUser,
            'password' => 'testpass',
        ]);
        $this->assertEquals(409, $res['code']);
    }

    // --- Repo CRUD ---

    public function testCreateRepo(): void
    {
        $repoName = 'int-test-' . uniqid() . '.git';
        $res = $this->post('/repos/' . $repoName);
        $this->assertEquals(201, $res['code']);
        $this->assertEquals($repoName, $res['body']['name']);
    }

    public function testCreateDuplicateRepo(): void
    {
        $repoName = 'int-dup-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->post('/repos/' . $repoName);
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
        $repoName = 'int-get-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->get('/repos/' . $repoName);
        $this->assertEquals(200, $res['code']);
        $this->assertEquals($repoName, $res['body']['name']);
    }

    public function testGetRepoNotFound(): void
    {
        $res = $this->get('/repos/no-such-repo-' . uniqid() . '.git');
        $this->assertEquals(404, $res['code']);
    }

    public function testDeleteRepo(): void
    {
        $repoName = 'int-del-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->delete('/repos/' . $repoName);
        $this->assertEquals(200, $res['code']);
    }

    // --- Visibility ---

    public function testSetPublic(): void
    {
        $repoName = 'int-pub-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->put('/repos/' . $repoName . '/public');
        $this->assertEquals(200, $res['code']);
        $this->assertTrue($res['body']['public']);
    }

    public function testSetPrivate(): void
    {
        $repoName = 'int-priv-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $this->put('/repos/' . $repoName . '/public');
        $res = $this->put('/repos/' . $repoName . '/private');
        $this->assertEquals(200, $res['code']);
        $this->assertFalse($res['body']['public']);
    }

    // --- User management ---

    public function testAddUserToRepo(): void
    {
        $repoName = 'int-useradd-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->post('/repos/' . $repoName . '/users/' . self::$otherUser);
        $this->assertEquals(200, $res['code']);
    }

    public function testRemoveUserFromRepo(): void
    {
        $repoName = 'int-userrm-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $this->post('/repos/' . $repoName . '/users/' . self::$otherUser);
        $res = $this->delete('/repos/' . $repoName . '/users/' . self::$otherUser);
        $this->assertEquals(200, $res['code']);
    }

    public function testListUsersInRepo(): void
    {
        $repoName = 'int-userlist-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->get('/repos/' . $repoName . '/users');
        $this->assertEquals(200, $res['code']);
        $this->assertIsArray($res['body']);
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

    // --- Change password ---

    public function testChangePasswordSuccess(): void
    {
        $res = $this->post('/changepass', [
            'username' => self::$testUser,
            'password' => self::$testPass,
            'newPassword' => 'newtestpass',
        ]);
        $this->assertEquals(200, $res['code']);

        // Restore original password using new password for auth
        $ch = curl_init($this->url('/changepass'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                'username' => self::$testUser,
                'password' => 'newtestpass',
                'newPassword' => self::$testPass,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode(self::$testUser . ':newtestpass'),
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $headerSize = $info['header_size'];
        $body = json_decode(substr($response, $headerSize), true);
        $this->assertEquals(200, $info['http_code'], 'Restore failed: ' . ($body['error'] ?? ''));
    }

    // --- CI/CD ---

    public function testCicdListHooks(): void
    {
        $repoName = 'int-cicd-' . uniqid() . '.git';
        $this->post('/repos/' . $repoName);
        $res = $this->get('/repos/' . $repoName . '/cicd');
        $this->assertEquals(200, $res['code']);
        $this->assertArrayHasKey('hooks', $res['body']);
    }
}
