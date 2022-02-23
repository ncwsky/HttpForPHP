<?php

namespace HttpForPHP;

class HttpSrv
{
    use SrvMsg;

    public static function run(&$argv, $config, $swoole = false)
    {
        if ($swoole) {
            if (defined('SWOOLE_VERSION')) {
                $srv = new SwooleSrv($config);
            } elseif (self::workermanCheck()) {
                $srv = new WorkerManSrv($config);
            } else {
                exit("Swolle: no install | Workerman: " . self::err());
            }
        } else {
            //自动检测环境优先workerman
            if (self::workermanCheck()) {
                $srv = new WorkerManSrv($config);
            } elseif (defined('SWOOLE_VERSION')) {
                $srv = new SwooleSrv($config);
            } else {
                exit("Swolle: no install | Workerman: " . self::err());
            }
        }
        $srv->run($argv);
    }

    public static function workermanCheck()
    {
        if (!in_array("pcntl", get_loaded_extensions())) {
            self::err('Extension pcntl check fail');
            return false;
        }
        if (!in_array("posix", get_loaded_extensions())) {
            self::err('Extension posix check fail');
            return false;
        }

        $check_func_map = array(
            "stream_socket_server",
            "stream_socket_accept",
            "stream_socket_client",
            "pcntl_signal_dispatch",
            "pcntl_signal",
            "pcntl_alarm",
            "pcntl_fork",
            "posix_getuid",
            "posix_getpwuid",
            "posix_kill",
            "posix_setsid",
            "posix_getpid",
            "posix_getpwnam",
            "posix_getgrnam",
            "posix_getgid",
            "posix_setgid",
            "posix_initgroups",
            "posix_setuid",
            "posix_isatty",
        );
        // 获取php.ini中设置的禁用函数
        if ($disable_func_string = ini_get("disable_functions")) {
            $disable_func_map = array_flip(explode(",", $disable_func_string));
        }
        // 遍历查看是否有禁用的函数
        foreach ($check_func_map as $func) {
            if (isset($disable_func_map[$func])) {
                self::err("Function $func may be disabled. Please check disable_functions in php.ini\nsee http://www.workerman.net/doc/workerman/faq/disable-function-check.html\n");
                return false;
            }
        }
        return true;
    }
}
