<?php

namespace HttpForPHP;

class HttpSrv
{
    use SrvMsg;

    const VERSION = '1.2';

    public static function run(&$argv, $config, $swoole = false)
    {
        $srvClass = [];
        if (SrvBase::workermanCheck()) $srvClass[] = WorkerManSrv::class;
        if (defined('SWOOLE_VERSION')) $srvClass[] = SwooleSrv::class;
        if (empty($srvClass)) {
            exit("Swolle: no install | Workerman: " . self::err());
        }
        /**
         * @var WorkerManSrv|SwooleSrv $srv
         */
        $class = $swoole ? end($srvClass) : reset($srvClass);
        $srv = new $class($config);
        $srv->run($argv);
    }
}
