<?php

namespace Lugit;

use Exception;

class GitCommandParser
{

	private $parsed_command;
	public function __construct(string $command, string $repo_path = '/home/git', string $username)
	{

		$this->parsed_command = $this->parse($command);
		if (!str_ends_with($this->parsed_command['repo'], '.git')) {
			error_log("Invalid repo path: " . $this->parsed_command['repo']);
			exit(1);
		}

		$path = $repo_path . '/' . substr($this->parsed_command['repo'], 0, -4);

		if (!is_dir($path)) {
			error_log("Repository not found: " . $this->parsed_command['repo']);
			exit(1);
		}

		$repoConfig = RepoConfig::load($path);
		
		if (!$repoConfig->hasUser($username)) {
			error_log("Access denied");
			exit(1);
		}

		$this->parsed_command['repo'] = $path;
	}

	public function getParsedCommand(): array
	{
		return $this->parsed_command;
	}

	public function checkCommand(): bool
	{
		return ($this->parsed_command === null);
	}

	public function run(): bool
	{
		if ($this->parsed_command === null) {
			return false;
		}
		return $this->execute($this->parsed_command);
	}



	private function parse(string $ssh_command): ?array
	{
		$valid_commands = [
			'git-upload-pack',
			'git-receive-pack',
			'git-upload-archive'
		];

		// Находим команду
		$cmd = strtok($ssh_command, ' ');
		if (!in_array($cmd, $valid_commands)) {
			return null;
		}

		// Получаем остаток команды
		$rest = trim(strtok(''));
		if (empty($rest)) {
			return null;
		}

		$repo = trim($rest, "'\"");

		if (!preg_match('/^[a-zA-Z0-9\/\._\-]+$/', $repo)) {
			error_log("Invalid repo path: $repo");
			return null;
		}

		// Запрещаем .. и пути начинающиеся с точки
		if (strpos($repo, '..') !== false || $repo[0] === '.') {
			error_log("Path traversal attempt: $repo");
			return null;
		}

		return [
			'command' => $cmd,
			'repo' => $repo,
			'safe_repo' => escapeshellarg($repo)
		];
	}


	private function execute(array $parsed_command, string $git_path = '/usr/bin/git'): bool
	{

		$repo_path = $parsed_command['repo'];
		$git_cmd = $parsed_command['command'];

		$cmd_map = [
			'git-upload-pack' => $git_path . '-upload-pack',
			'git-receive-pack' => $git_path . '-receive-pack',
			'git-upload-archive' => $git_path . '-upload-archive'
		];

		if (!isset($cmd_map[$git_cmd])) {
			return false;
		}

		$binary = $cmd_map[$git_cmd];

		// Проверяем существование репозитория
		if (!is_dir($repo_path) || !is_file($repo_path . '/HEAD')) {
			error_log("Repository not found: $repo_path");
			return false;
		}

		$descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
		$process = proc_open([$binary, $repo_path], $descriptors, $pipes);

		if (is_resource($process)) {
			return (proc_close($process) === 0);
		}

		return false;
	}
}
