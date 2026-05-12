<?php

namespace Lugit;

class Password
{
	public static function hash(string $password): string
	{
		return password_hash($password, PASSWORD_ARGON2ID);
	}

	public static function verify(string $password, string $hash): bool
	{
		if (strlen($hash) === 64 && preg_match('/^[a-f0-9]{64}$/', $hash)) {
			return hash_equals($hash, hash('sha256', $password));
		}
		return password_verify($password, $hash);
	}

	public static function needsRehash(string $hash): bool
	{
		return password_needs_rehash($hash, PASSWORD_ARGON2ID);
	}
}
