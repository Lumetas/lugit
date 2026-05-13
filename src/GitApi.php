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
			return null;
		}
		$config = RepoConfig::load($repoPath);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
			return null;
		}
		return $config;
	}

	private function checkReadAccess(string $repoInput): ?array
	{
		$result = $this->parseRepoInput($repoInput);
		if ($result === null) {
			$this->sendError(404, "Repository not found");
			return null;
		}
		$config = RepoConfig::load($result['path']);
		if ($config->public || $config->hasUser($this->currentUser['username'])) {
			return $result;
		}
		$this->sendError(403, "Access denied");
		return null;
	}

	private function checkCicdAccess(string $repoInput): ?array
	{
		$result = $this->parseRepoInput($repoInput);
		if ($result === null) {
			$this->sendError(404, "Repository not found");
			return null;
		}
		$config = RepoConfig::load($result['path']);
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
			return null;
		}
		return $result;
	}

	public function changePassword(): void
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

	public function authenticate(): void
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

	public function register(): void
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

	public function login(): void
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

	public function getCurrentUser(): void
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

	public function listRepos(): void
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

	public function getRepo(string $repoInput): void
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

	public function createRepo(string $repoName): void
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

	public function deleteRepo(string $repoName): void
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

	public function listUsers(string $repoInput): void
	{
		$result = $this->checkReadAccess($repoInput);
		$config = RepoConfig::load($result['path']);
		$this->sendJson($config->allowedUsers);
	}

	public function addUser(string $repoName, string $targetUser): void
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

	public function removeUser(string $repoName, string $targetUser): void
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

	public function setVisibility(string $repoName, bool $public): void
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

	public function cicdListHooks(string $repoInput): void
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

	public function cicdSetHook(string $repoName, string $branch): void
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

	public function cicdDelHook(string $repoName, string $branch): void
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

	public function cicdGetLogs(string $repoInput, string $branch): void
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

	public function cicdCleanLogs(string $repoInput, string $branch): void
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

	public function cicdRunHook(string $repoInput, string $branch): void
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

	public function listSshKeys(): void
	{
		$username = $this->currentUser['username'];
		
		$keys = new KeysRepository();
		$keys->listKeys($username);
		$this->sendJson(['keys' => $keys->listKeys($username)]);
	}

	public function addSshKey(): void
	{
		$data = json_decode(file_get_contents('php://input'), true);
		$key = $data['key'] ?? null;
		
		if (!$key) {
			$this->sendError(400, "Missing 'key' field");
		}
		
		$key = trim($key);
		if (!preg_match('/^(ssh-rsa|ssh-ed25519|ssh-ecdsa|ecdsa-sha2-nistp\d+|sk-userauth@openssh\.com) /i', $key)) {
			$this->sendError(400, "Invalid SSH key format");
		}
		
		$keys = new KeysRepository();
		$id = $keys->add($this->currentUser['username'], $key);
		$keys->save();
		$this->sendJson(['id' => $id ?? 'unknown']);
		
	}

	public function deleteSshKey(): void
	{
		$data = json_decode(file_get_contents('php://input'), true);
		$keyId = $data['id'] ?? '';
		
		if (!$keyId) {
			$this->sendError(400, "Missing 'id' field");
		}
		
		$username = $this->currentUser['username'];
	
		
		$keys = new KeysRepository();
		$keys->remove($username, $keyId);
		$keys->save();
	}

	private function sendJson(array $data, int $code = 200): void
	{
		http_response_code($code);
		header('Content-Type: application/json');
		echo json_encode($data);
		exit;
	}

	public function sendError(int $code, string $message, array $extraHeaders = []): void
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
