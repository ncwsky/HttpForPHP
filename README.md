## 说明
使用workerman|swoole实现http服务，把现有其他框架的代码简单改为常驻内存http服务，未实现session支持，最适合用于接口服务，已有在yii项目中运行，参见自带的yii示例。  
原理就是对PHP的$_SERVER $_COOKIE $_FILES $_REQUEST $_POST $_GET全局变量重置数据   
$_SESSION太麻烦就没有处理 毕竟只针对接口应用的服务 所以就没有必要  

如果接入的应用接口里有使用session，将不可用，需要屏蔽此类请求，另做处理。
demo文件里是yii框架的示例入口文件

数据库、缓存服务等如果断开了需要自行在你接入应用框架里做处理，或者像demo里的一样通过捕获异常直接重启当前进程

此Http服务支持异步处理，在请求参数里带上async=1时，会投递到异步进程中进行处理。 async 可通过 define('ASYNC_NAME,'重名');  

    注意：接口代码不要有exit die，你使用的框架request参见yii示例重置数据，如yii的request里的数据使用了静态变量存储，会造成内存泄露或某些使用了request的逻辑代码错误。 


## 使用
```
composer require ncwsky/http-for-php
``` 

## 示例代码
### 通过 composer的autolad
```
#!/usr/bin/env php
<?php
define('LOG_PATH', __DIR__.'/log');

require_once __DIR__ . '/../../vendor/autoload.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$srv = new \HttpForPHP\WorkerManSrv(require(__DIR__ . '/http.conf.php'));
$srv->run($argv);
```

### 或直接通过自带Load.php载入
```
#!/usr/bin/env php
<?php
define('LOG_PATH', __DIR__.'/log');

require __DIR__ . '/../../HttpForPHP/Load.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$srv = new \HttpForPHP\WorkerManSrv(require(__DIR__ . '/http.conf.php'));
$srv->run($argv);
```

## 配置项示例
示例配置代码参见demo目录下 http.conf.php，最好先看一遍示例配置。
```
    return [
        'name' => 'Demo', //服务名
        'ip' => '0.0.0.0', //监听地址
        'port' => 6502, //监听地址
        'init_php' => __DIR__.'/base.php', //初始文件
        'php_run' => function($req, $connection=null) {} //请求处理匿名函数 或 在初始文件时定义request_php_run请求处理函数【可reload】
        'setting' => [
            'count' => 20,    // 异步非阻塞CPU核数的1-4倍最合理 同步阻塞按实际情况来填写 如50-100
            #'task_worker_num' => 10, //异步任务进程数 配置了异步处理才能生效  异步处理耗时过多的会阻塞后续的异步处理请求
            #'max_request' => 500, //最大请求数 默认0 进程内达到此请求重启进程 可能存在不规范的代码造成内存泄露 这里达到一定请求释放下内存
            'stdoutFile' => __DIR__ . '/http.log', //终端输出
            'pidFile' => __DIR__ . '/http.pid',
            'logFile' => __DIR__ . '/http.log', //日志文件
            # 'user' => 'www', //设置worker/task子进程的进程用户 提升服务器程序的安全性
        ]
    ]
``` 

