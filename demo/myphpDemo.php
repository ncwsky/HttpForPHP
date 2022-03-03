#!/usr/bin/env php
<?php
define('LOG_PATH', __DIR__.'/log');

require __DIR__ . '/../../HttpForPHP/Load.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

//print_r($cfg);
\HttpForPHP\HttpSrv::run($argv, require(__DIR__ . '/myphp.http.php'));