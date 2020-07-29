#!/usr/bin/env php
<?php
define('LOG_PATH', __DIR__.'/log');

require __DIR__ . '/../../HttpForPHP/Load.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

//print_r($cfg);
$srv = new \HttpForPHP\WorkerManSrv(require(__DIR__ . '/http.conf.php'));
$srv->run($argv);