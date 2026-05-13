<?php

namespace Lugit\Controllers;

use BMND\Http\Request;
use BMND\Router\Error;
use BMND\Router\Route;
use Lugit\RepoPage;

class WebController
{
	#[Route('/')]
	public function dashboard(): void
	{
		readfile(__DIR__ . '/../static/index.html');
	}

	#[Route('/{user}/{repo}')]
	#[Route('/{user}/{repo}/')]
	public function repo(string $user, string $repo, RepoPage $page): void
	{
		$page->handle($user, $repo);
	}

	#[Route('/{user}')]
	#[Route('/{user}/')]
	public function user(string $user, RepoPage $page): void
	{
		$page->handleUserProfile($user);
	}

}
