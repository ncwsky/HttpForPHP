<?php
declare(strict_types=1);

namespace HttpForPHP;

define('BASE_PATH', __DIR__);
#define('LOG_PATH', __DIR__.'/log'); #日志目录
#is_file(BASE_PATH . '/config.php') && Config::load(BASE_PATH . '/config.php'); //引入默认配置文件

class Helper{
    const CODE_OK = 0; //成功
    const CODE_FAIL = 1; //失败
    public static $httpCodeStatus = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
    );
    public static $tpl = array(
        self::CODE_OK => '操作成功',
        self::CODE_FAIL => '操作失败'
    );

    public static function tpl($code)
    {
        return isset(self::$tpl[$code]) ? self::$tpl[$code] : 'null';
    }
    public static function ok($data = null, $info = null, $ext=null, $code = self::CODE_OK)
    {
        $out = ['code' => $code, 'msg' => ($info === null ? self::tpl($code) : $info)];
        $out['data'] = $data;
        $out['ext'] = $ext;
        return $out;
    }
    public static function fail($info = null, $code = self::CODE_FAIL, $data = null)
    {
        $out = ['code' => $code, 'msg' => ($info === null ? self::tpl($code) : $info)];
        $out['data'] = $data;
        return $out;
    }
    //json_encode 缩写
    public static function toJson($res, $option=0){
        if($option==0 && defined('JSON_UNESCAPED_UNICODE'))
            $option = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        return json_encode($res, $option);
    }

    /**
     * 获取[多维]数组指定键名的值 不存在返回默认值.
     * examples
     * // working with array
     * $username = Helper::getValue($_POST, 'username');
     * or
     * $value = Helper::getValue($users, 'x.y');
     * or
     * $value = Helper::getValue($versions, ['x', 'y']);
     * // working with anonymous function
     * $fullName = Helper::getValue($user, function ($user, $defaultValue) {
     *     return $user->firstName . ' ' . $user->lastName;
     * });
     *
     * @param array $array array or object to extract value from
     * @param string|\Closure|array $key
     * @param mixed $default
     * @return mixed
     */
    public static function getValue($array, $key, $default = null)
    {
        if (!is_array($array)) return $default;
        if ($key instanceof \Closure) {
            return $key($array, $default);
        }
        if (is_string($key) && strpos($key, '.')) {
            $key = explode('.', $key);
        }
        if (is_array($key)) {
            foreach ($key as $k) {
                if (is_array($array) && (isset($array[$k]) || array_key_exists($k, $array))) {
                    $array = $array[$k];
                } else {
                    return $default;
                }
            }
            return $array;
        }
        return is_array($array) && (isset($array[$key]) || array_key_exists($key, $array)) ? $array[$key] : $default;
    }
    //日期检测函数(格式:2007-5-6 15:30:33)
    public static function is_date($date) {
        return preg_match('/^(\d{4})-(0[1-9]|[1-9]|1[0-2])-(0[1-9]|[1-9]|1\d|2\d|3[0-1])(| (0[0-9]|[0-9]|1[0-9]|2[0-3]):([0-5][0-9]|0[0-9]|[0-9])(|:([0-5][0-9]|0[0-9]|[0-9])))$/', $date);
    }
    //Ymd检测函数(格式:2007-5-6)
    public static function is_ymd($date) {
        return preg_match('/^(\d{4})-(0[1-9]|[1-9]|1[0-2])(|-(0[1-9]|[1-9]|1\d|2\d|3[0-1]))$/', $date);
    }
    //His检测函数(格式:15:30[:33])
    public static function is_his($date) {
        return preg_match('/^(0[0-9]|[0-9]|1[0-9]|2[0-3]):([0-5][0-9]|0[0-9]|[0-9])(|:([0-5][0-9]|0[0-9]|[0-9]))$/', $date);
    }
    //判断email格式是否正确
    public static function is_email($email) {
        return strlen($email) > 6 && preg_match("/^[\w\-\.]+@[\w\-\.]+(\.\w+)+$/", $email);
    }
    //判断是否手机号
    public static function is_tel($mobile){
        return strlen($mobile)==11 && preg_match("/^1[3456789]\d{9}$/", $mobile);
    }
    //判断是否IP
    public static function is_ip($ip){
        return preg_match("/^((?:(?:25[0-5]|2[0-4]\d|((1\d{2})|([1-9]?\d)))\.){3}(?:25[0-5]|2[0-4]\d|((1\d{2})|([1-9]?\d))))$/", $ip);
    }
    //检测是否手机浏览 return bool true|false
    public static function is_mobile() {
        static $is_mobile;
        if ( isset($is_mobile) ) return $is_mobile;

        if ( empty($_SERVER['HTTP_USER_AGENT']) ) {
            $is_mobile = false;
        } elseif ( strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false // many mobile devices (all iPhone, iPad, etc.)
            || strpos($_SERVER['HTTP_USER_AGENT'], 'Android') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'Silk/') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'Kindle') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'BlackBerry') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'Opera Mini') !== false
            || strpos($_SERVER['HTTP_USER_AGENT'], 'Opera Mobi') !== false ) {
            $is_mobile = true;
        } else {
            $is_mobile = false;
        }
        return $is_mobile;
    }
    //是否微信
    public static function is_weixin(){
        if ( isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false ) {
            return true;
        }
        return false;
    }
    public static $isProxy = false;
    //获取用户真实地址 返回用户ip  type:0 返回IP地址 1 返回IPV4地址数字
    public static function getIp($type=0) {
        $realIP = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if(self::$isProxy){
            if (isset($_SERVER['HTTP_X_REAL_IP'])) {
                $realIP = $_SERVER['HTTP_X_REAL_IP'];
            } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) { //有可能被伪装
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                foreach ($arr as $ip) { //取X-Forwarded-For中第x个非unknown的有效IP字符?
                    $ip = trim($ip);
                    if ($ip != 'unknown') {
                        $realIP = $ip;
                        break;
                    }
                }
            }
        }
        return $type ? sprintf("%u", ip2long($realIP)) : $realIP;
        //$long = sprintf("%u", ip2long($realIP));
        //$realIP = $long ? [$realIP, $long] : ['0.0.0.0', 0];
        //return $realIP[$type == 0 ? 0 : 1];
    }
}

//http请求-验证IP 定义常量 ALLOW_HTTP_IP : ip,ip...
function verify_ip($ip=''){
    static $ipList;
    $allow_ip = defined('ALLOW_HTTP_IP') ? ALLOW_HTTP_IP : ''; //多个,分隔
    if(!$allow_ip) return true;

    if($ip==='') $ip = Helper::getIp();
    if (!isset($ipList)) $ipList = explode(',', $allow_ip);

    foreach ($ipList as $allow) { // [10.1.1.2,10.1.,127.0.0.1]
        if (strpos($ip, $allow) === 0) {
            return true;
        }
    }
    return false;
}
//json_encode 缩写
function toJson($res, $option=0){
    return Helper::toJson($res, $option);
}