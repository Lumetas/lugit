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
				$users = Config::getUsers();
				foreach ($users as $u) {
					if ($u['username'] === $username && $u['password'] === $password) {
						$this->currentUser = ['username' => $username];
						return;
					}
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
			'password' => hash('sha256', $password)
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
