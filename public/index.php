<?php
namespace Lugit;
require_once __DIR__ . '/../vendor/autoload.php';
use Lugit\Config;
use BMND\Router\Router;


Router::setup('../src/Controllers', 'Lugit\\Controllers\\', '..', Config::get('routesCache', true))->run();
