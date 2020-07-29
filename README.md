##使用
```
composer require ncwsky/http-for-php
```

##示例代码

###通过 composer的autolad
```
#!/usr/bin/env php
<?php
define('LOG_PATH', __DIR__.'/log');

require_once __DIR__ . '/../../vendor/autoload.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$srv = new \HttpForPHP\WorkerManSrv(require(__DIR__ . '/http.conf.php'));
$srv->run($argv);
```


###或直接通过自带Load.php载入
```
#!/usr/bin/env php
<?php
define('LOG_PATH', __DIR__.'/log');

require __DIR__ . '/../../HttpForPHP/Load.php';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

$srv = new \HttpForPHP\WorkerManSrv(require(__DIR__ . '/http.conf.php'));
$srv->run($argv);
```

##说明
http.conf.php代码参见demo目录下

如果接入的应用接口里有使用session，将不可用，需要屏蔽此类请求，另做处理。
demo文件里是yii框架的示例入口文件

数据库、缓存服务等如果断开了需要自行在你接入应用框架里做处理，或者像demo里的一样通过捕获异常直接重启当前进程

此Http服务支持异步处理，在请求参数里带上async=1时，会投递到异步进程中进行处理。 async 可通过 define('ASYNC_NAME,'重名');
