#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lugit\Config;
use Lugit\Password;

$username = readline("Username: ");
$password = readline("Password: ");
$password2 = readline("Confirm password: ");
$cicd = readline("Enable CI/CD permissions (y/n): ");

$allowCicd = $cicd === 'y';

if ($password !== $password2) {
	echo "Passwords do not match\n";
	exit(1);
}

$users = Config::getUsers();

foreach ($users as $user) {
	if ($user['username'] === $username) {
		echo "User already exists\n";
		exit(1);
	}
}
$users[] = [
	'username' => $username,
	'password' => Password::hash($password),
	'allow_cicd' => $allowCicd
];

Config::setUsers($users);
Config::save();

echo "User created successfully\n";
