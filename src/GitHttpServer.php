<?php

namespace Lugit;

class GitHttpServer
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
		$method = $_SERVER['REQUEST_METHOD'];

		if (preg_match('#^/?([^/]+)/([^/]+)(/.*)?$#', $path, $matches)) {
			$username = $matches[1];
			$repoName = $matches[2];
			$action = $matches[3] ?? '/info/refs';
		} else {
			error_log("Invalid path: $path");
			$this->sendError(400, "Invalid path");
			return;
		}

		error_log("User: $username, Repo: $repoName, Action: $action");

		if (!Utils::isValidUsername($username) || !Utils::isValidRepoName($repoName)) {
			$this->sendError(404, "Repository not found");
			return;
		}

		if (in_array($username, $this->excludedFolders) || in_array($repoName, $this->excludedFolders)) {
			$this->sendError(404, "Repository not found");
			return;
		}

		if (RepoCache::hasRepo($username, $repoName)) {
			$repoPath = $this->basePath . '/' . $username . '/' . $repoName;
		} else {
			$repoPath = Utils::findRepoPath($username, $repoName);
			if ($repoPath === null) {
				$this->sendError(404, "Repository not found");
				return;
			}
		}

		if (!Utils::isGitRepo($repoPath)) {
			$this->sendError(404, "Repository not found");
			return;
		}

		if (str_starts_with($action, '/info/refs')) {
			$this->handleInfoRefs($repoPath, $action);
		} elseif (str_starts_with($action, '/git-upload-pack')) {
			$this->handleUploadPack($repoPath);
		} elseif (str_starts_with($action, '/git-receive-pack')) {
			$this->handleReceivePack($repoPath);
		} else {
			$this->sendError(400, "Unknown service");
		}
	}

	private function handleInfoRefs(string $repoPath, string $action): void
	{
		$service = $_GET['service'] ?? null;

		if ($service !== 'git-upload-pack' && $service !== 'git-receive-pack') {
			$this->sendError(400, "Unknown service");
			return;
		}

		$repoConfig = RepoConfig::load($repoPath);

		if ($service === 'git-receive-pack') {
			if (!$this->authenticate()) {
				header('HTTP/1.1 401 Unauthorized');
				header('WWW-Authenticate: Basic realm="Git-Server"');
				$this->sendError(401, "Authentication required");
				return;
			}
			if (!$repoConfig->hasUser($this->currentUser['username'])) {
				$this->sendError(403, "Access denied");
				return;
			}
		} else {
			if (!$repoConfig->public) {
				if (!$this->authenticate()) {
					$this->sendError(401, "Authentication required");
					return;
				}
				if (!$repoConfig->hasUser($this->currentUser['username'])) {
					$this->sendError(403, "Access denied");
					return;
				}
			}
		}

		$serviceName = ltrim($service, 'git-');
		$gitDir = escapeshellarg($repoPath);
		$cmd = "git $serviceName --stateless-rpc --advertise-refs " . $gitDir;

		$result = Utils::runGit($cmd, $repoPath);

		if ($result['exitCode'] !== 0) {
			$this->sendError(500, "Git error: " . $result['stderr']);
			return;
		}

		$refs = $result['stdout'];

		$initialPkt = Utils::pktLine("# service=$service\n");

		header('Content-Type: application/x-git-' . $serviceName . '-advertisement');
		header('Cache-Control: no-cache');

		echo $initialPkt;
		echo "0000";
		echo $refs;
	}

	private function handleUploadPack(string $repoPath): void
	{
		$repoConfig = RepoConfig::load($repoPath);

		if (!$repoConfig->public) {
			if (!$this->authenticate()) {
				header('HTTP/1.1 401 Unauthorized');
				header('WWW-Authenticate: Basic realm="Git-Server"');
				$this->sendError(401, "Authentication required");
				return;
			}
			if (!$repoConfig->hasUser($this->currentUser['username'])) {
				header('HTTP/1.1 403 Forbidden');
				$this->sendError(403, "Access denied");
				return;
			}
		}

		$this->handleGitRpc($repoPath, 'upload-pack');
	}

	private function handleReceivePack(string $repoPath): void
	{
		$repoConfig = RepoConfig::load($repoPath);

		if (!$this->authenticate()) {
			header('HTTP/1.1 401 Unauthorized');
			header('WWW-Authenticate: Basic realm="Git-Server"');
			$this->sendError(401, "Authentication required");
			return;
		}

		if (!$repoConfig->hasUser($this->currentUser['username'])) {
			$this->sendError(403, "Access denied");
			return;
		}

		$this->handleGitRpc($repoPath, 'receive-pack');
	}

	private function handleGitRpc(string $repoPath, string $service): void
	{
		$input = file_get_contents('php://input');
		$gitDir = escapeshellarg($repoPath);
		$cmd = "git $service --stateless-rpc " . $gitDir;

		$descriptor = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		];

		$pipes = [];
		$process = proc_open($cmd, $descriptor, $pipes, $repoPath);

		if (!is_resource($process)) {
			$this->sendError(500, "Failed to run git $service");
			return;
		}

		fwrite($pipes[0], $input);
		fclose($pipes[0]);

		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		proc_close($process);

		header('Content-Type: application/x-git-' . $service . '-result');
		header('Cache-Control: no-cache');
		echo $output;
	}

	private function authenticate(): bool
	{
		if ($this->currentUser !== null) {
			return true;
		}

		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

		if ($authHeader !== null && str_starts_with($authHeader, 'Basic ')) {
			$credentials = base64_decode(substr($authHeader, 6));
			if ($credentials && str_contains($credentials, ':')) {
				[$username, $password] = explode(':', $credentials, 2);
				$users = Config::getUsers();
				foreach ($users as $u) {
					if ($u['username'] === $username) {
						if (Password::verify($password, $u['password'])) {
							$this->currentUser = ['username' => $username];
							return true;
						}
					}
				}
			}
		}

		return false;
	}

	private function sendError(int $code, string $message): void
	{
		if ($code == 401) {
			header('WWW-Authenticate: Basic realm="Git Server"');
		}
		http_response_code($code);
		header('Content-Type: text/plain');
		echo "$message\n";
		exit(1);
	}
}