#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Lugit\Config;

$username = readline("Username: ");
$password = readline("Password: ");
$password2 = readline("Confirm password: ");
$cicd = readline("Enable CI/CD permissions (y/n): ");

if ($cicd !== 'y') {
	$cicd = false;
} else {
	$cicd = true;
}

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
	'password' => hash('sha256', $password),
	'allow_cicd' => $cicd
];

Config::setUsers($users);
Config::save();

echo "User created successfully\n";
