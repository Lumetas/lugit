<?php

namespace Lugit;

class KeysRepository
{
	private array $keys = ['u2k' => [], 'k2u' => []];
	private string $path;

	public function __construct()
	{
		Config::load();
		$this->path = Config::get('keysDump', __DIR__ . '/../keys.dump');

		if (file_exists($this->path)) {
			$this->load($this->path);
		} else {
			$this->save();
		}
	}

	private function load(string $path): void
	{
		$this->keys = unserialize(file_get_contents($path));
	}

	public function save(): void
	{
		file_put_contents($this->path, serialize($this->keys));
		exec("nohup php " . __DIR__ . "/DumpAllKeys.php 2>&1 &");
	}

	public function add(string $username, string $key): string
	{
		$keyname = explode(' ', $key)[2];
		$this->keys['u2k'][$username][$key] = $keyname;
		$this->keys['k2u'][$key] = $username;
		return $keyname;
	}

	public function remove(string $username, string $key): void
	{
		unset($this->keys['u2k'][$username][$key]);
		unset($this->keys['k2u'][$key]);
	}

	public function listKeys(string $username): array
	{
		return $this->keys['u2k'][$username] ?? [];
	}

	public function yieldKeys(): \Generator {
		foreach ($this->keys['k2u'] as $key => $username) {
			yield $key => $username;
		}
	}

	public function getAllKeys(): array {
		return $this->keys['k2u'];
	}
}
