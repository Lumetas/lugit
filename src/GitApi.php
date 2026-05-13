<?php

namespace Lugit;

class GitApi
{
	private string $basePath;
	private array $excludedFolders;
	private ?array $currentUser = null;

	public function __construct()
	{
		Config::init(dirname(__DIR__) . '/config.json');
		RepoCache::init(Config::getCacheFile());
		$this->basePath = Config::getRepositoriesPath();
		$this->excludedFolders = Config::getExcludedFolders();
	}

	public function handle(): void
	{
		$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

		if (preg_match('#^/?api/v1/repos/?$#', $path)) {
			$this->authenticate();
			if ($method === 'GET') {
				$this->listRepos();
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1] . '/' . $matches[2];
			if ($method === 'GET') {
				$this->getRepo($repoInput);
			} elseif ($method === 'POST') {
				$this->createRepo($matches[2]);
			} elseif ($method === 'DELETE') {
				$this->deleteRepo($matches[2]);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			if ($method === 'GET') {
				$this->getRepo($repoName);
			} elseif ($method === 'POST') {
				$this->createRepo($repoName);
			} elseif ($method === 'DELETE') {
				$this->deleteRepo($repoName);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/([^/]+)/users/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1] . '/' . $matches[2];
			if ($method === 'GET') {
				$this->listUsers($repoInput);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/users/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1];
			if ($method === 'GET') {
				$this->listUsers($repoInput);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/users/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			$targetUser = $matches[2];
			if ($method === 'POST') {
				$this->addUser($repoName, $targetUser);
			} elseif ($method === 'DELETE') {
				$this->removeUser($repoName, $targetUser);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/(public|private)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			$visibility = $matches[2];
			if ($method === 'PUT') {
				$this->setVisibility($repoName, $visibility === 'public');
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/cicd/logs/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1];
			$branch = urldecode($matches[2]);
			if ($method === 'GET') {
				$this->cicdGetLogs($repoInput, $branch);
			} elseif ($method === 'DELETE') {
				$this->cicdCleanLogs($repoInput, $branch);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/cicd/([^/]+)/run/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1];
			$branch = urldecode($matches[2]);
			if ($method === 'POST') {
				$this->cicdRunHook($repoInput, $branch);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/cicd/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			$branch = urldecode($matches[2]);
			if ($method === 'POST') {
				$this->cicdSetHook($repoName, $branch);
			} elseif ($method === 'DELETE') {
				$this->cicdDelHook($repoName, $branch);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/cicd/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1];
			if ($method === 'GET') {
				$this->cicdListHooks($repoInput);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/([^/]+)/cicd/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1] . '/' . $matches[2];
			if ($method === 'GET') {
				$this->cicdListHooks($repoInput);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/([^/]+)/cicd/logs/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1] . '/' . $matches[2];
			$branch = urldecode($matches[3]);
			if ($method === 'GET') {
				$this->cicdGetLogs($repoInput, $branch);
			} elseif ($method === 'DELETE') {
				$this->cicdCleanLogs($repoInput, $branch);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/([^/]+)/cicd/([^/]+)/run/?$#', $path, $matches)) {
			$this->authenticate();
			$repoInput = $matches[1] . '/' . $matches[2];
			$branch = urldecode($matches[3]);
			if ($method === 'POST') {
				$this->cicdRunHook($repoInput, $branch);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/user/?$#', $path)) {
			if ($method === 'GET') {
				$this->getCurrentUser();
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/login/?$#', $path)) {
			if ($method === 'POST') {
				$this->login();
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/register/?$#', $path)) {
			if ($method === 'POST') {
				$this->register();
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/changepass/?$#', $path)) {
			if ($method === 'POST') {
				$this->changePassword();
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} else {
			$this->sendError(404, "Not found");
		}
	}

	private function getUserRepoPath(string $repoName): ?string {
		$username = $this->currentUser['username'];
		return Utils::findRepoPath($username, $repoName);
	}

	private function parseRepoInput(string $input): ?array
	{
		if (str_contains($input, '/')) {
			[$owner, $repoName] = explode('/', $input, 2);
		} else {
			$owner = $this->currentUser['username'];
			$repoName = $input;
		}
		$repoPath = Utils::findRepoPath($owner, $repoName);
		if ($repoPath === null) {
			return null;
		}
		return ['owner' => $owner, 'repoName' => $repoName, 'path' => $repoPath];
	}

	private function checkMyRepoAccess(string $repoName): ?RepoConfig
	{
		$repoPath = $this->getUserRepoPath($repoName);
		if ($repoPath === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($repoPath);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}
		return $config;
	}

	private function checkReadAccess(string $repoInput): ?array
	{
		$result = $this->parseRepoInput($repoInput);
		if ($result === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($result['path']);
		if ($config->public || $config->hasUser($this->currentUser['username'])) {
			return $result;
		}
		$this->sendError(403, "Access denied");
	}

	private function checkCicdAccess(string $repoInput): ?array
	{
		$result = $this->parseRepoInput($repoInput);
		if ($result === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($result['path']);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}
		return $result;
	}

	private function changePassword(): void
	{
		$data = json_decode(file_get_contents('php://input'), true);
		$username = $data['username'] ?? null;
		$password = $data['password'] ?? null;
		$newPassword = $data['newPassword'] ?? null;

		if (!$username || !$password || !$newPassword) {
			$this->sendError(400, "username, password and newPassword required");
		}

		$users = Config::getUsers();
		foreach ($users as &$u) {
			if ($u['username'] === $username) {
				if (Password::verify($password, $u['password'])) {
					$u['password'] = Password::hash($newPassword);
					Config::setUsers($users);
					Config::save();
					$this->sendJson(['message' => "Password changed"]);
					return;
				}
			}
		}

		$this->sendError(401, "Invalid credentials");
	}

	private function authenticate(): void
	{
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

		if ($authHeader !== null && str_starts_with($authHeader, 'Basic ')) {
			$credentials = base64_decode(substr($authHeader, 6));
			if ($credentials && str_contains($credentials, ':')) {
				[$username, $password] = explode(':', $credentials, 2);
				$user = Config::getUser($username);
				if ($user !== null && Password::verify($password, $user['password'])) {
					$this->currentUser = $user;
					return;
				}
			}
		}

		$this->sendError(401, "Unauthorized", ['WWW-Authenticate' => 'Basic realm="Git Server API"']);
	}

	private function register(): void
	{
		if (!Config::get('enableRegister', false)) {
			$this->sendError(403, "Registration is disabled");
		}

		$data = json_decode(file_get_contents('php://input'), true);
		$username = $data['username'] ?? null;
		$password = $data['password'] ?? null;

		if (!$username || !$password) {
			$this->sendError(400, "username, password required");
		}

		$users = Config::getUsers();
		foreach ($users as $u) {
			if ($u['username'] === $username) {
				$this->sendError(409, "Username already exists");
			}
		}

		$users[] = [
			'username' => $username,
			'password' => Password::hash($password),
			'allow_cicd' => false
		];

		Config::setUsers($users);
		Config::save();

		$this->sendJson(['message' => 'User registered successfully'], 201);
	}

	private function getRepoConfig(string $repoPath): ?RepoConfig
	{
		if (!Utils::isGitRepo($repoPath)) {
			return null;
		}
		return RepoConfig::load($repoPath);
	}

	private function login(): void
	{
		$data = json_decode(file_get_contents('php://input'), true);
		$username = $data['username'] ?? null;
		$password = $data['password'] ?? null;

		if (!$username || !$password) {
			$this->sendError(400, "username and password required");
		}

		$users = Config::getUsers();
		foreach ($users as $u) {
			if ($u['username'] === $username && Password::verify($password, $u['password'])) {
				$this->sendJson(['username' => $username]);
				return;
			}
		}

		$this->sendError(401, "Invalid credentials");
	}

	private function getCurrentUser(): void
	{
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

		if ($authHeader && str_starts_with($authHeader, 'Basic ')) {
			$credentials = base64_decode(substr($authHeader, 6));
			if ($credentials && str_contains($credentials, ':')) {
				[$username, $password] = explode(':', $credentials, 2);
				$user = Config::getUser($username);
				if ($user !== null && Password::verify($password, $user['password'])) {
					$this->sendJson(['username' => $username]);
					return;
				}
			}
		}

		$this->sendJson(['username' => null]);
	}

	private function listRepos(): void
	{
		$cache = RepoCache::getAll();
		$currentUser = $this->currentUser['username'];
		$result = [];

		foreach ($cache as $username => $repos) {
			foreach ($repos as $repoName => $config) {
				if (!$config['allowedUsers'] || !in_array($currentUser, $config['allowedUsers'])) {
					continue;
				}
				$result[] = [
					'username' => $username,
					'name' => $repoName,
					'public' => $config['public'],
					'allowedUsers' => $config['allowedUsers']
				];
			}
		}

		$this->sendJson($result);
	}

	private function getRepo(string $repoInput): void
	{
		$result = $this->parseRepoInput($repoInput);
		if ($result === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($result['path']);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}
		$this->sendJson([
			'username' => $result['owner'],
			'name' => $result['repoName'],
			'public' => $config->public,
			'allowedUsers' => $config->allowedUsers
		]);
	}

	private function createRepo(string $repoName): void
	{
		if (!Utils::isValidRepoName($repoName)) {
			$this->sendError(400, "Invalid repository name");
		}

		$username = $this->currentUser['username'];
		$repoPath = $this->basePath . '/' . $username . '/' . $repoName;
		if (is_dir($repoPath)) {
			$this->sendError(409, "Repository already exists");
		}

		if (!is_dir($this->basePath . '/' . $username)) {
			mkdir($this->basePath . '/' . $username, 0755, true);
		}

		Utils::createBareRepo($repoPath);

		$config = new RepoConfig(
			allowedUsers: [$this->currentUser['username']],
			public: false
		);
		$config->save($repoPath);

		RepoCache::addRepo($username, $repoName, [
			'public' => $config->public,
			'allowedUsers' => $config->allowedUsers
		]);

		$this->sendJson([
			'username' => $username,
			'name' => $repoName,
			'public' => false,
			'allowedUsers' => [$this->currentUser['username']]
		], 201);
	}

	private function deleteRepo(string $repoName): void
	{
		$repoPath = $this->getUserRepoPath($repoName);
		if ($repoPath === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($repoPath);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}
		Utils::deleteRepo($repoPath);
		RepoCache::removeRepo($this->currentUser['username'], $repoName);
		$this->sendJson(['message' => "Repository '$repoName' deleted"]);
	}

	private function listUsers(string $repoInput): void
	{
		$result = $this->checkReadAccess($repoInput);
		$config = RepoConfig::load($result['path']);
		$this->sendJson($config->allowedUsers);
	}

	private function addUser(string $repoName, string $targetUser): void
	{
		$repoPath = $this->getUserRepoPath($repoName);
		if ($repoPath === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($repoPath);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}

		if ($config->hasUser($targetUser)) {
			$this->sendJson(['message' => "User '$targetUser' already has access"]);
		}

		$config->addUser($targetUser);
		$config->save($repoPath);

		RepoCache::updateRepo($this->currentUser['username'], $repoName, [
			'allowedUsers' => $config->allowedUsers
		]);

		$this->sendJson(['message' => "User '$targetUser' added to '$repoName'"]);
	}

	private function removeUser(string $repoName, string $targetUser): void
	{
		$repoPath = $this->getUserRepoPath($repoName);
		if ($repoPath === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($repoPath);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}

		if (!$config->hasUser($targetUser)) {
			$this->sendError(404, "User not found");
		}

		if ($targetUser === $this->currentUser['username']) {
			$this->sendError(400, "Cannot remove yourself");
		}

		$config->removeUser($targetUser);
		$config->save($repoPath);

		RepoCache::updateRepo($this->currentUser['username'], $repoName, [
			'allowedUsers' => $config->allowedUsers
		]);

		$this->sendJson(['message' => "User '$targetUser' removed from '$repoName'"]);
	}

	private function setVisibility(string $repoName, bool $public): void
	{
		$repoPath = $this->getUserRepoPath($repoName);
		if ($repoPath === null) {
			$this->sendError(404, "Repository not found");
		}
		$config = RepoConfig::load($repoPath);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}

		$config->public = $public;
		$config->save($repoPath);

		RepoCache::updateRepo($this->currentUser['username'], $repoName, [
			'public' => $public
		]);

		$this->sendJson([
			'username' => $this->currentUser['username'],
			'name' => $repoName,
			'public' => $public,
			'message' => "Repository '$repoName' is now " . ($public ? 'public' : 'private')
		]);
	}

	private function requireCicdAccess(): void
	{
		$allow = $this->currentUser['allow_cicd'] ?? false;
		if (!$allow) {
			$this->sendError(403, "CI/CD access denied. Contact administrator to enable CI/CD permissions.");
		}
	}

	private function ensureLugitDirs(string $repoPath): void
	{
		$lugitDir = $repoPath . '/lugit';
		@mkdir($lugitDir . '/hooks', 0755, true);
		@mkdir($lugitDir . '/logs', 0755, true);
	}

	private function writePostReceiveHook(string $repoPath): void
	{
		$hooksDir = $repoPath . '/hooks';
		@mkdir($hooksDir, 0755, true);
		$hookPath = $hooksDir . '/post-receive';

		if (file_exists($hookPath)) {
			$existing = file_get_contents($hookPath);
			if (str_contains($existing, 'Lugit CI/CD post-receive hook')) {
				return;
			}
			rename($hookPath, $hookPath . '.lugit.bak');
		}

		$script = <<<'HOOK'
#!/bin/sh
# Lugit CI/CD post-receive hook - DO NOT EDIT
LUGIT_DIR="$(cd "$(dirname "$0")/.." && pwd)/lugit"
if [ ! -d "$LUGIT_DIR/hooks" ]; then
    exit 0
fi
mkdir -p "$LUGIT_DIR/logs"
while read oldrev newrev refname; do
    case "$refname" in
        refs/heads/*)
            BRANCH="${refname#refs/heads/}"
            HOOK_SCRIPT="$LUGIT_DIR/hooks/$BRANCH"
            LOG_FILE="$LUGIT_DIR/logs/$BRANCH"
            if [ -f "$HOOK_SCRIPT" ] && [ -x "$HOOK_SCRIPT" ]; then
                echo "=== $(date) === Push to $BRANCH ===" >> "$LOG_FILE"
                nohup "$HOOK_SCRIPT" "$oldrev" "$newrev" "$refname" >> "$LOG_FILE" 2>&1 &
            fi
            ;;
    esac
done
HOOK;

		file_put_contents($hookPath, $script);
		chmod($hookPath, 0755);
	}

	private function cicdListHooks(string $repoInput): void
	{
		$result = $this->checkCicdAccess($repoInput);
		$this->requireCicdAccess();

		$hooksDir = $result['path'] . '/lugit/hooks';

		$hooks = [];
		if (is_dir($hooksDir)) {
			$files = scandir($hooksDir);
			foreach ($files as $file) {
				if ($file === '.' || $file === '..') continue;
				$hooks[] = $file;
			}
		}

		$this->sendJson(['hooks' => $hooks]);
	}

	private function cicdSetHook(string $repoName, string $branch): void
	{
		$this->checkMyRepoAccess($repoName);
		$this->requireCicdAccess();
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$data = json_decode(file_get_contents('php://input'), true);
		$scriptBase64 = $data['script'] ?? null;

		if (!$scriptBase64) {
			$this->sendError(400, "Missing 'script' field with base64-encoded content");
		}

		$scriptContent = base64_decode($scriptBase64, true);
		if ($scriptContent === false) {
			$this->sendError(400, "Invalid base64 encoding");
		}

		$repoPath = $this->getUserRepoPath($repoName);
		$this->ensureLugitDirs($repoPath);

		$hookFile = $repoPath . '/lugit/hooks/' . $branch;
		$hookDir = dirname($hookFile);
		if (!is_dir($hookDir)) {
			mkdir($hookDir, 0755, true);
		}

		if (file_put_contents($hookFile, $scriptContent) === false) {
			$this->sendError(500, "Failed to write hook script");
		}

		chmod($hookFile, 0755);

		$this->writePostReceiveHook($repoPath);

		$this->sendJson(['message' => "CI/CD hook installed for branch '$branch' in '$repoName'"]);
	}

	private function cicdDelHook(string $repoName, string $branch): void
	{
		$this->checkMyRepoAccess($repoName);
		$this->requireCicdAccess();
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$repoPath = $this->getUserRepoPath($repoName);
		$hookFile = $repoPath . '/lugit/hooks/' . $branch;

		if (!file_exists($hookFile)) {
			$this->sendError(404, "Hook not found for branch '$branch'");
		}

		unlink($hookFile);
		$this->sendJson(['message' => "CI/CD hook removed for branch '$branch'"]);
	}

	private function cicdGetLogs(string $repoInput, string $branch): void
	{
		$result = $this->checkCicdAccess($repoInput);
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$logFile = $result['path'] . '/lugit/logs/' . $branch;

		$content = '';
		if (file_exists($logFile)) {
			$content = file_get_contents($logFile);
		}

		$this->sendJson(['logs' => [$branch => $content]]);
	}

	private function cicdCleanLogs(string $repoInput, string $branch): void
	{
		$result = $this->checkCicdAccess($repoInput);
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$logFile = $result['path'] . '/lugit/logs/' . $branch;

		if (!file_exists($logFile)) {
			$this->sendError(404, "Logs not found for branch '$branch'");
		}

		unlink($logFile);
		$this->sendJson(['message' => "Logs cleared for branch '$branch'"]);
	}

	private function cicdRunHook(string $repoInput, string $branch): void
	{
		$result = $this->checkCicdAccess($repoInput);
		$this->requireCicdAccess();
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$hookFile = $result['path'] . '/lugit/hooks/' . $branch;

		if (!file_exists($hookFile) || !is_executable($hookFile)) {
			$this->sendError(404, "Hook not found for branch '$branch'");
		}

		$logFile = $result['path'] . '/lugit/logs/' . $branch;
		$logDir = dirname($logFile);
		if (!is_dir($logDir)) {
			mkdir($logDir, 0755, true);
		}

		$logEntry = "=== " . date('c') . " === Manual run on $branch ===\n";
		@file_put_contents($logFile, $logEntry, FILE_APPEND);

		$cmd = "nohup " . escapeshellarg($hookFile) . " >> " . escapeshellarg($logFile) . " 2>&1 &";
		chdir($result['path']);
		exec($cmd);

		$this->sendJson(['message' => "CI/CD hook triggered manually for branch '$branch'"]);
	}

	private function sendJson(array $data, int $code = 200): void
	{
		http_response_code($code);
		header('Content-Type: application/json');
		echo json_encode($data);
		exit;
	}

	private function sendError(int $code, string $message, array $extraHeaders = []): void
	{
		http_response_code($code);
		header('Content-Type: application/json');
		foreach ($extraHeaders as $name => $value) {
			header("$name: $value");
		}
		echo json_encode(['error' => $message]);
		exit;
	}
}