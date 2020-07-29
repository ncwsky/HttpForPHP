<?php
namespace HttpForPHP;
spl_autoload_register('\HttpForPHP\myAutoLoader');

require __DIR__ . '/Base.php';
Log::init();
function myAutoLoader($class_name){
    static $_class = array();
    $class_name = strtr($class_name, '\\', '/');
    if (isset($_class[$class_name])) return true;
    //命名空间类加载 仿psr4
    if ($pos = strrpos($class_name, '/')) {
        $path = dirname(__DIR__). ($class_name[0] == '/' ? '' : '/') . substr($class_name, 0, $pos);
        $name = substr($class_name, $pos + 1);
        if (autoLoadFile($name, $path, '.php')) {
            $_class[$class_name] = true;
            return true;
        }
    }
    if (autoLoadFile($class_name, __DIR__, '.php')) {
        $_class[$class_name] = true;
        return true;
    }
    return false;
}
function autoLoadFile($name, $path = __DIR__, $ext='.php') {
    static $php = array();
    $path = $path . (substr($path, -1, 1) == '/' ? '' : '/') . $name . $ext;
    if (isset($php[$path])) {
        return true;
    }
    if (is_file($path)) {
        include $path;
        $php[$path] = true;
        return true;
    } else {
        $php[$path] = false;
    }
    return $php[$path];
}

