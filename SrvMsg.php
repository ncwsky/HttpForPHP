<?php
namespace HttpForPHP;

trait SrvMsg
{
    public static $myMsg = '';
    public static $myCode = 0;
    //消息记录
    public static function msg($msg=null, $code=0){
        if ($msg === null) {
            return self::$myMsg;
        } else {
            self::$myMsg = $msg;
            self::$myCode = $code;
        }
    }
    //错误提示设置或读取
    public static function err($msg=null, $code=1){
        if ($msg === null) {
            return self::$myMsg;
        } else {
            self::msg($msg, $code);
        }
    }
}