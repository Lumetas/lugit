<?php

namespace Lugit;

use BMND\DI;

require_once __DIR__ . '/../vendor/autoload.php';

$keys = new KeysRepository();

$keyString = "";
foreach ($keys->yieldKeys() as $key => $username) {
	$keyString .= "command=\"php " . escapeshellarg(__DIR__ . "/SshWrapper.php") . " '$username'\",no-port-forwarding,no-X11-forwarding,no-agent-forwarding $key\n";
}

file_put_contents(getenv('HOME') . '/.ssh/authorized_keys', $keyString);
echo $keyString;
echo "Done!\n";
