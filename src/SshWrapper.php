<?php
namespace Lugit;
require_once __DIR__ . '/../vendor/autoload.php';

use Lugit\Config;
Config::load();


$command = getenv('SSH_ORIGINAL_COMMAND');

if (empty($command)) {
    echo "Hello, " . $argv[1] . "!\n";
	echo "Have a nice coding day!\n";
    exit(1);
}

$parser = new GitCommandParser($command, Config::get('repositoriesPath', '/home/git'), trim($argv[1]));

if ($parser->checkCommand()) {
	error_log("Invalid command format, you fucking ssh cheater!");
	exit(1);
}
if (!$parser->run()) {
	error_log("Sorry, please create an issue");
	exit(1);
}
