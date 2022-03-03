<?php
define('APP_PATH', __DIR__ . '/app');
require __DIR__ . '/myphp.conf.php';

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/vendor/myphps/myphp/base.php';

//请求处理函数
function request_php_run($req, $connection = null)
{
    myphp::setEnv('headers', \HttpForPHP\SrvBase::$instance->getHeader($req));
    myphp::setRawBody(\HttpForPHP\SrvBase::$instance->getRawBody($req)); //file_get_contents("php://input")
    myphp::Run(function ($code, $data, $header) use ($connection) {
        //$code = isset(myphp::$httpCodeStatus[$code]) ? $code : 200;
        // 发送http
        \HttpForPHP\SrvBase::$instance->httpSend($connection, $code, $header, $data);
    }, false);
}