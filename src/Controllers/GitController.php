<?php

namespace Lugit\Controllers;

use BMND\Http\Response;
use BMND\Router\Error;
use BMND\Router\Route;
use Lugit\GitHttpServer;

#[route('/{user}/{repo}.git')]
class GitController
{

	public function __construct(private GitHttpServer $api) {}

	#[Route('/info/refs')]
	public function infoRefs(string $user, string $repo): void
	{
		$repoPath = $this->api->checkRepoAndUser($user, $repo);
		if ($repoPath === false) {
			$this->api->sendError(404, "Repository not found");
		}
		$this->api->handleInfoRefs($repoPath);
	}

	#[Route('/git-upload-pack', method: 'POST')]
	public function uploadPack(string $user, string $repo): void
	{
		$repoPath = $this->api->checkRepoAndUser($user, $repo);
		if ($repoPath === false) {
			$this->api->sendError(404, "Repository not found");
		}
		$this->api->handleUploadPack($repoPath);
	}

	#[Route('/git-receive-pack', method: 'POST')]
	public function receivePack(string $user, string $repo): void
	{
		$repoPath = $this->api->checkRepoAndUser($user, $repo);
		if ($repoPath === false) {
			$this->api->sendError(404, "Repository not found");
		}

		$this->api->handleReceivePack($repoPath);
	}

	#[Error(500)]
	public function internalError(\Throwable $e): void
	{
		error_log($e->getMessage());
	}

	#[Error(404)]
	public function notFound(): void
	{
		$this->api->sendError(404, "Not found");
	}
}
