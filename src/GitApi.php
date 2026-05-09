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
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/users/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			if ($method === 'GET') {
				$this->listUsers($repoName);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/users/([^/]+)/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			$username = $matches[2];
			if ($method === 'POST') {
				$this->addUser($repoName, $username);
			} elseif ($method === 'DELETE') {
				$this->removeUser($repoName, $username);
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
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/cicd/logs/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			if ($method === 'GET') {
				$this->cicdGetLogs($repoName);
			} else {
				$this->sendError(405, "Method not allowed");
			}
		} elseif (preg_match('#^/?api/v1/repos/([^/]+)/cicd/([^/]+)/run/?$#', $path, $matches)) {
			$this->authenticate();
			$repoName = $matches[1];
			$branch = urldecode($matches[2]);
			if ($method === 'POST') {
				$this->cicdRunHook($repoName, $branch);
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
			$repoName = $matches[1];
			if ($method === 'GET') {
				$this->cicdListHooks($repoName);
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
		} else {
			$this->sendError(404, "Not found");
		}
	}

	private function authenticate(): void
	{
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

		if ($authHeader !== null && str_starts_with($authHeader, 'Basic ')) {
			$credentials = base64_decode(substr($authHeader, 6));
			if ($credentials && str_contains($credentials, ':')) {
				[$username, $password] = explode(':', $credentials, 2);
				$user = Config::getUser($username);
				if ($user !== null && $user['password'] === hash('sha256', $password)) {
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
			$this->sendError(400, "username, password and email required");
		}

		$users = Config::getUsers();
		foreach ($users as $u) {
			if ($u['username'] === $username) {
				$this->sendError(409, "Username already exists");
			}
		}


		$users[] = [
			'username' => $username,
			'password' => hash('sha256', $password),
			'allow_cicd' => false
		];

		Config::setUsers($users);
		Config::save();
	}

	private function getRepoConfig(string $name): ?RepoConfig
	{
		if (in_array($name, $this->excludedFolders)) {
			return null;
		}

		$repoPath = $this->basePath . '/' . $name;
		if (!Utils::isGitRepo($repoPath)) {
			return null;
		}

		return RepoConfig::load($repoPath);
	}

	private function checkRepoAccess(string $name): void
	{
		$config = $this->getRepoConfig($name);
		if ($config === null) {
			$this->sendError(404, "Repository not found");
		}
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}
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
			if ($u['username'] === $username && $u['password'] === $password) {
				$this->sendJson(['username' => $username]);
				return;
			}
		}

		$this->sendError(401, "Invalid credentials");
	}

	private function getCurrentUser(): void
	{
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
		$username = null;

		if ($authHeader && str_starts_with($authHeader, 'Basic ')) {
			$credentials = base64_decode(substr($authHeader, 6));
			if ($credentials && str_contains($credentials, ':')) {
				[$username] = explode(':', $credentials, 2);
			}
		}

		$this->sendJson(['username' => $username]);
	}

	private function listRepos(): void
	{
		$repos = Utils::listRepositories();
		$result = [];

		foreach ($repos as $repoName) {
			$repoPath = $this->basePath . '/' . $repoName;
			$config = RepoConfig::load($repoPath);
			if (!$config->hasUser($this->currentUser['username'])) {
				continue;
			}
			$result[] = [
				'name' => $repoName,
				'public' => $config->public,
				'allowedUsers' => $config->allowedUsers
			];
		}

		$this->sendJson($result);
	}

	private function getRepo(string $name): void
	{
		$config = $this->getRepoConfig($name);
		if ($config === null) {
			$this->sendError(404, "Repository not found");
		}
		if (!$config->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
		}
		$this->sendJson([
			'name' => $name,
			'public' => $config->public,
			'allowedUsers' => $config->allowedUsers
		]);
	}

	private function createRepo(string $name): void
	{
		if (!Utils::isValidRepoName($name)) {
			$this->sendError(400, "Invalid repository name");
		}

		$repoPath = $this->basePath . '/' . $name;
		if (is_dir($repoPath)) {
			$this->sendError(409, "Repository already exists");
		}

		Utils::createBareRepo($repoPath);

		$config = new RepoConfig(
			allowedUsers: [$this->currentUser['username']],
			public: false
		);
		$config->save($repoPath);

		$this->sendJson([
			'name' => $name,
			'public' => false,
			'allowedUsers' => [$this->currentUser['username']]
		], 201);
	}

	private function deleteRepo(string $name): void
	{
		$this->checkRepoAccess($name);
		$repoPath = $this->basePath . '/' . $name;
		Utils::deleteRepo($repoPath);
		$this->sendJson(['message' => "Repository '$name' deleted"]);
	}

	private function listUsers(string $name): void
	{
		$this->checkRepoAccess($name);
		$repoPath = $this->basePath . '/' . $name;
		$config = RepoConfig::load($repoPath);
		$this->sendJson($config->allowedUsers);
	}

	private function addUser(string $name, string $username): void
	{
		$this->checkRepoAccess($name);
		$repoPath = $this->basePath . '/' . $name;
		$config = RepoConfig::load($repoPath);

		if ($config->hasUser($username)) {
			$this->sendJson(['message' => "User '$username' already has access"]);
		}

		$config->addUser($username);
		$config->save($repoPath);

		$this->sendJson(['message' => "User '$username' added to '$name'"]);
	}

	private function removeUser(string $name, string $username): void
	{
		$this->checkRepoAccess($name);
		$repoPath = $this->basePath . '/' . $name;
		$config = RepoConfig::load($repoPath);

		if (!$config->hasUser($username)) {
			$this->sendError(404, "User not found");
		}

		if ($username === $this->currentUser['username']) {
			$this->sendError(400, "Cannot remove yourself");
		}

		$config->removeUser($username);
		$config->save($repoPath);

		$this->sendJson(['message' => "User '$username' removed from '$name'"]);
	}

	private function setVisibility(string $name, bool $public): void
	{
		$this->checkRepoAccess($name);
		$repoPath = $this->basePath . '/' . $name;
		$config = RepoConfig::load($repoPath);

		$config->public = $public;
		$config->save($repoPath);

		$this->sendJson([
			'name' => $name,
			'public' => $public,
			'message' => "Repository '$name' is now " . ($public ? 'public' : 'private')
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

	private function cicdListHooks(string $repoName): void
	{
		$this->checkRepoAccess($repoName);
		$this->requireCicdAccess();

		$repoPath = $this->basePath . '/' . $repoName;
		$hooksDir = $repoPath . '/lugit/hooks';

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
		$this->checkRepoAccess($repoName);
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

		$repoPath = $this->basePath . '/' . $repoName;
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
		$this->checkRepoAccess($repoName);
		$this->requireCicdAccess();
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$repoPath = $this->basePath . '/' . $repoName;
		$hookFile = $repoPath . '/lugit/hooks/' . $branch;

		if (!file_exists($hookFile)) {
			$this->sendError(404, "Hook not found for branch '$branch'");
		}

		unlink($hookFile);
		$this->sendJson(['message' => "CI/CD hook removed for branch '$branch'"]);
	}

	private function cicdGetLogs(string $repoName): void
	{
		$this->checkRepoAccess($repoName);

		$repoPath = $this->basePath . '/' . $repoName;
		$logsDir = $repoPath . '/lugit/logs';

		$logs = [];
		if (is_dir($logsDir)) {
			$files = scandir($logsDir);
			foreach ($files as $file) {
				if ($file === '.' || $file === '..') continue;
				$logPath = $logsDir . '/' . $file;
				$logs[$file] = file_get_contents($logPath);
			}
		}

		$this->sendJson(['logs' => $logs]);
	}

	private function cicdRunHook(string $repoName, string $branch): void
	{
		$this->checkRepoAccess($repoName);
		$this->requireCicdAccess();
		if (str_contains($branch, '..')) {
			$this->sendError(400, "Invalid branch name");
		}

		$repoPath = $this->basePath . '/' . $repoName;
		$hookFile = $repoPath . '/lugit/hooks/' . $branch;

		if (!file_exists($hookFile) || !is_executable($hookFile)) {
			$this->sendError(404, "Hook not found for branch '$branch'");
		}

		$logFile = $repoPath . '/lugit/logs/' . $branch;
		$logDir = dirname($logFile);
		if (!is_dir($logDir)) {
			mkdir($logDir, 0755, true);
		}

		$cmd = "echo \"=== $(date) === Manual run on $branch ===\" >> " . escapeshellarg($logFile)
			. " && nohup " . escapeshellarg($hookFile) . " >> " . escapeshellarg($logFile) . " 2>&1 &";

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
