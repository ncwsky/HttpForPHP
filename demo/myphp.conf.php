<?php
$cfg = array(
    'db' => [
        'type' => 'pdo',    //数据库类型 仅有 mysqli pdo
        'dbms' => 'mysql', //数据库
        'server' => '192.168.0.232',//数据库主机
        'name' => 'service',    //数据库名称
        'user' => 'root',    //数据库用户
        'pwd' => 'root',    //数据库密码
        'port' => 3306,     // 端口
        'char' => 'utf8',    //数据库编码
        #'prod' => false
    ],
    'mongodb' => [
        'server' => '192.168.0.246',//数据库主机
        'name' => 'test',    //数据库名称
        'port' => 27017,     // 端口
    ],
    'db2' => [
        'type' => 'pdo',
        'dbms' => 'sqlite',
        'name' => __DIR__ . '/sqlite3.db'
    ],
    'redis' => array(
        //'name' => 'redis',
        'host' => '192.168.0.246',
        'port' => 6379,
        'password' => '123456',
        'select' => 1, //选择库
        //'pconnect'=>true, //长连接
    ),
    'debug' => true,
    'root_dir' => '',
    'url_para_str' => '/',
    'http_ip' => ['127.0.0.1'], //http ip白名单
    'log_dir' => __DIR__ . '/log/', //日志记录主目录名称
    'log_size' => 4194304,// 日志文件大小限制
    'log_level' => 1,// 日志记录等级
);
define('CONN_HEARTBEAT_TIME', 3); //连接心跳时间 0不检测
define('CONN_MAX_IDLE_TIME', 9);  //连接最大空闲时间 0不限制
function redis($name='redis'){return lib_redis::getInstance(GetC($name));} //返回redis