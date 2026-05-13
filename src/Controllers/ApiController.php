<?php

namespace Lugit\Controllers;

use BMND\Http\Response;
use BMND\Router\Error;
use BMND\Router\Route;
use Lugit\GitApi;

#[route('/api/v1')]
class ApiController
{

	public function __construct(private GitApi $api)
	{
		$this->api->authenticate();
	}


	#[Route('/repos')]
	public function repos(): void
	{
		$this->api->listRepos();
	}



	#[Route('/repos/{repo}/users')]
	public function usersInUserRepo(string $repo): void
	{
		$this->api->listUsers($repo);
	}
	#[Route('/repos/{user}/{repo}/users')]
	public function usersInRepo(string $user, string $repo): void
	{
		$this->usersInUserRepo($user . '/' . $repo);
	}


	#[Route('/repos/{repoName}/users/{targetUser}', method: 'POST')]
	public function addUserToUserRepo(string $repoName, string $targetUser): void
	{
		$this->api->addUser($repoName, $targetUser);
	}

	#[Route('/repos/{repoName}/users/{targetUser}', method: 'DELETE')]
	public function removeUserFromUserRepo(string $repoName, string $targetUser): void
	{
		$this->api->removeUser($repoName, $targetUser);
	}

	#[Route('/repos/{repoName}/{visibility}', method: 'PUT')]
	public function setVisibility(string $repoName, string $visibility): void
	{
		$this->api->setVisibility($repoName, $visibility === 'public');
	}

	#[Route('/repos/{repo}/cicd/logs/{branch}')]
	public function cicdGetLogs(string $repo, string $branch): void
	{

		$this->api->cicdGetLogs($repo, $branch);
	}

	#[Route('/repos/{repo}/cicd/{branch}', method: 'DELETE')]
	public function cicdCleanLogs(string $repo, string $branch): void
	{
		$this->api->cicdCleanLogs($repo, $branch);
	}

	#[Route('/repos/{repo}/cicd/{branch}/run')]
	public function cicdRunHook(string $repo, string $branch): void
	{
		$this->api->cicdRunHook($repo, $branch);
	}


	#[Route('/repos/{repo}/cicd/{branch}', method: 'POST')]
	public function cicdSetHook(string $repo, string $branch): void
	{
		$this->api->cicdSetHook($repo, $branch);
	}

	#[Route('/repos/{repo}/cicd/{branch}', method: 'DELETE')]
	public function cicdDelHook(string $repo, string $branch): void
	{
		$this->api->cicdDelHook($repo, $branch);
	}


	#[Route('/repos/{repo}/cicd')]
	public function cicdListHooksInUserRepo(string $repo): void
	{

		$this->api->cicdListHooks($repo);
	}
	#[Route('/repos/{user}/{repo}/cicd')]
	public function cicdListHooks(string $user, string $repo): void
	{

		$this->cicdListHooksInUserRepo($user . '/' . $repo);
	}

	#[Route('/repos/{user}/{repo}/cicd/{branch}/logs')]
	public function cicdGetLogsInRepo(string $user, string $repo, string $branch): void
	{
		$repoInput = $user . '/' . $repo;
		$this->api->cicdGetLogs($repoInput, $branch);
	}

	#[Route('/repos/{user}/{repo}/cicd/{branch}/run', method: 'POST')]
	public function cicdRunHookInUserRepo(string $user, string $repo, string $branch): void
	{
		$this->cicdRunHook($user . '/' . $repo, $branch);
	}

	#[Route('/user')]
	public function getCurrentUser(): void
	{

		$this->api->getCurrentUser();
	}

	#[Route('/login', method: 'POST')]
	public function login(): void
	{
		$this->api->login();
	}

	#[Route('/register', method: 'POST')]
	public function register(): void
	{
		$this->api->register();
	}

#[Route('/changepass', method: 'POST')]
	public function changePassword(): void
	{
		$this->api->changePassword();
	}

	#[Route('/ssh/keys', method: 'GET')]
	public function listSshKeys(): void
	{
		$this->api->listSshKeys();
	}

	#[Route('/ssh/keys', method: 'POST')]
	public function addSshKey(): void
	{
		$this->api->addSshKey();
	}

	#[Route('/ssh/keys', method: 'DELETE')]
	public function deleteSshKey(): void
	{
		$this->api->deleteSshKey();
	}

	#[Route('/repos/{user}/{repo}')]
	public function repo(string $user, string $repo): void
	{
		$this->getRepo($user . '/' . $repo);
		$this->getRepo($user . '/' . $repo);
	}

	#[Route('/repos/{repo}', method: 'GET')]
	public function getRepo(string $repo): void
	{
		$this->api->getRepo($repo);
	}

	#[Route('/repos/{repo}', method: 'POST')]
	public function createRepo(string $repo): void
	{
		$this->api->createRepo($repo);
	}

	#[Route('/repos/{repo}', method: 'DELETE')]
	public function deleteRepo(string $repo): void
	{
		$this->api->deleteRepo($repo);
	}



	#[Error(404)]
	public function notFound(): void
	{
		$this->api->sendError(404, "Not found");
	}

	#[Error(500)]
	public function internalError(\Throwable $e): Response
	{
		$response = new Response(json_encode(['error' => $e->getMessage()]), 500);
		return $response;
	}
}
