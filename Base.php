<?php
declare(strict_types=1);

namespace HttpForPHP;

//系统开始时间
define('SYS_START_TIME', microtime(TRUE));//时间戳.微秒数
define('SYS_TIME', time());//时间戳和微秒数
// 记录内存初始使用
define('MEMORY_LIMIT_ON', function_exists('memory_get_usage'));
MEMORY_LIMIT_ON && define('SYS_MEMORY', memory_get_usage());
//系统变量
define('IS_CLI', PHP_SAPI == 'cli');
define('DS', '/');
define('SRV_DEBUG', true);
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
    public static function is_json($data){
        return json_decode($data)===null?false:true;
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
        /*static $realIP;
        if (!IS_CLI && isset($realIP)) {
            return $realIP[$type == 0 ? 0 : 1];
        }*/
        //重置ipv6
        //if(isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
        //  $_SERVER['REMOTE_ADDR']='127.0.0.1';
        //}
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



    public static $validBefore; //验证的前置操作 流程处理完请重置为null
    public static $validAfter; //验证的后置操作 流程处理完请重置为null

}
//配置处理类
class Config{
    public static $cfg = [];
    //载入配置
    public static function load($file){
        self::set(include $file);
    }
    //获取配置值 支持二维数组
    public static function get($name, $defVal = null){
        if ( false === ($pos = strpos($name, '.')) )
            return isset(self::$cfg[$name]) ? self::$cfg[$name] : $defVal;
        // 二维数组支持
        $name1 = substr($name,0,$pos); $name2 = substr($name,$pos+1);
        return isset(self::$cfg[$name1][$name2]) ? self::$cfg[$name1][$name2] : $defVal;
    }
    //动态设置配置值
    public static function set($name, $val=null){
        if(is_array($name)) {
            self::$cfg = array_merge(self::$cfg, $name); return;
        }
        if ( false === ($pos = strpos($name, '.')) ){
            self::$cfg[$name]=$val; return;
        }
        // 二维数组支持
        $name1 = substr($name,0,$pos); $name2 = substr($name,$pos+1);
        //$name = explode('.', $name);
        self::$cfg[$name1][$name2]=$val;
    }
    //删除配置
    public static function del($name){
        if ( false === ($pos = strpos($name, '.')) ){
            unset(self::$cfg[$name]); return;
        }
        // 二维数组支持
        $name1 = substr($name,0,$pos); $name2 = substr($name,$pos+1);
        unset(self::$cfg[$name1][$name2]);
    }
}
//http请求-验证IP
function verify_ip(){
    $allow_ip = GetC('http_ip');
    if($allow_ip){
        $ip = Helper::getIp();
        if (!in_array($ip, $allow_ip)) {
            return false;
        }
    }
    return true;
}
//转换字节数为其他单位 $byte:字节
function toByte($byte){
    $v = 'unknown';
    if($byte >= 1099511627776){
        $v = round($byte / 1099511627776  ,2) . 'TB';
    } elseif($byte >= 1073741824){
        $v = round($byte / 1073741824  ,2) . 'GB';
    } elseif($byte >= 1048576){
        $v = round($byte / 1048576 ,2) . 'MB';
    } elseif($byte >= 1024){
        $v = round($byte / 1024, 2) . 'KB';
    } else{
        $v = $byte . 'Byte';
    }
    return $v;
}
// 统计程序运行时间 秒
function run_time() {
    return number_format(microtime(TRUE) - SYS_START_TIME, 4);
}
// 统计程序内存开销
function run_mem() {
    return MEMORY_LIMIT_ON ? toByte(memory_get_usage() - SYS_MEMORY) : 'unknown';
}
//获取配置值 支持二维数组
function GetC($name, $defVal = NULL){
    return Config::get($name, $defVal);
}
//动态设置配置值
function SetC($name, $val){
    Config::set($name, $val);
}
//json_encode 缩写
function toJson($res, $option=0){
    return Helper::toJson($res, $option);
}

//字符截取 $string中汉字、英文字母、数字、符号每个在$len中占一个数，不存在汉字占两个字节的考虑
function cutstr($str, $len, $suffix = '', $offset=0) {
    if (function_exists('mb_substr')){
        $str = mb_substr($str, $offset, $len, 'UTF-8').(mb_strlen($str)>$len?$suffix:'');
    }
    else{
        preg_match_all('/./u', $str, $arr);//su 匹配所有的字符，包括换行符 /u 匹配所有的字符，不包括换行符
        //var_dump($arr);
        $str = implode('', array_slice($arr[0], $offset, $len));
        if (count($arr[0]) > $len) {
            $str .= $suffix;
        }
    }
    return $str;
}
function html_encode($content)
{
    if($content===null) return $content;
    return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function html_decode($content)
{
    if($content===null) return $content;
    return htmlspecialchars_decode($content, ENT_QUOTES);
}
/**
 * 获取输入参数 支持过滤和默认值
 * 使用方法:
 * <code>
 * Q('id',0); 获取id参数 自动判断get或者post
 * Q('post.name:htmlspecialchars'); 获取$_POST['name']
 * Q('get.:null'); 获取$_GET 且不执行过滤操作 filter=null
 * </code>
 * @param string $name 变量的名称 支持指定类型  post.name%s{1,20}:filter  {1,20}取值范围
 * @param mixed $defVal 变量的默认值
 * @param mixed $datas 要获取的额外数据源
 * @return mixed
 */
/*
string,bool,int,float,arr,date
%s,%b,%d,%f,%a,%date [2014-01-11 13:23:32 | 2014-01-11]
filter:fun1,fun2,/regx/i正则过滤
*/

function Q($name, $defVal='', $datas=null) {
    static $_PUT = null;
    $filter = $min = $max = null;
    $type = 's'; // 默认转换为字符串
    $scale = 0; //小数位处理 四舍五入
    if(strpos($name,':')){ // 指定过滤方法
        list($name,$filter) = explode(':',$name,2);
    }
    if ($filter === null) $filter = '\HttpForPHP\html_encode';
    if(strpos($name,'{') && substr($name,-1)=='}'){ // 指定长度范围 用于数字及字符串
        list($name,$size) = explode('{',substr($name,0,-1),2);
        if(strpos($size,',')){
            list($min,$max) = explode(',',$size,2);
            if($max==='') $max = null;
        }else $max = $size;
        #max min将会是数字字符串
    }
    if(strpos($name,'%')){ // 指定修饰符
        list($name,$type) = explode('%',$name,2);
        if($type && $type[0]=='.'){ // %.2f
            $scale=(int)substr($type,-2,1);
            $type=substr($type,-1);
        }
    }
    if(strpos($name,'.')!==false) { // 指定参数来源
        list($method,$name) = explode('.',$name,2);
        if(!$method) $method = 'auto';
    }else{ // 默认为_REQUEST
        $method = 'request';
    }
    //echo $method.'--'.$name.'--'.$type.'--'.$min.'--'.$max.'--'.$filter;
    switch($method) { #strtolower($method)
        case 'get' : $input = &$_GET; break;
        case 'post': $input = &$_POST; break;
        case 'put' :
            if(is_null($_PUT)) parse_str(file_get_contents('php://input'), $_PUT);
            $input = &$_PUT; break;
        case 'auto':
            switch($_SERVER['REQUEST_METHOD']) {
                case 'POST':
                    $input = &$_POST; break;
                case 'PUT':
                    if(is_null($_PUT)) parse_str(file_get_contents('php://input'), $_PUT);
                    $input = &$_PUT; break;
                default:
                    $input = &$_GET;
            }
            break;
        case 'request': $input = &$_REQUEST; break;
        case 'session': $input = &$_SESSION; break;
        case 'cookie' : $input = &$_COOKIE; break;
        case 'server' : $input = &$_SERVER; break;
        case 'globals': $input = &$GLOBALS; break;
        case 'data'   : $input = &$datas; break;
        case 'path'   :
            $input = array();
            if(!empty($_SERVER['PATH_INFO'])){
                $depr = GetC('url_para_str');
                $depr = $depr==null ? '/' : $depr;
                $input = explode($depr,trim($_SERVER['PATH_INFO'],$depr));
            }
            break;
        default:
            if($type=='d' || $type=='f'){
                $defVal = $type=='d'?(int)$defVal:(float)$defVal;
            }
            return $defVal;
    }
    $isAllVal = ''===$name;
    if($isAllVal) { // 获取全部变量
        $val = $input;
    }
    else{
        if(strpos($name,'.')){ //多维数组
            $val = Helper::getValue($input, $name);
        }else{
            $val = isset($input[$name]) ? $input[$name] : null;
        }
        if($val!==null) { // 取值处理
            if(is_string($val)) $val = trim($val);
        }
    }
    if(Helper::$validBefore instanceof \Closure){ //验证的前置操作
        $fun = Helper::$validBefore;
        $val = $fun($val);
    }
    if($filter!='null') { //过滤处理
        if(0 === strpos($filter,'/')){
            if(1 !== preg_match($filter,(string)$val)){// 支持正则验证
                return $defVal;
            }
        }else{ //多个过滤,分隔
            $filters = strpos($filter, ',')===false ? array($filter) : explode(',', $filter);
        }
        if(isset($filters)){
            foreach($filters as $filter){
                if(is_callable($filter)) {
                    $val = is_array($val) ? array_call_func($filter,$val) : $filter($val); // 参数过滤
                }else{ // ?? filter_var
                    $val = filter_var($val, is_numeric($filter) ? (int)$filter : filter_id($filter));
                    if(false === $val) return $defVal;
                }
            }
        }
    }
    #(int), (integer) - 转换为整形 integer (bool), (boolean) - 转换为布尔类型 boolean (float), (double), (real) - 转换为浮点型 float
    #(string) - 转换为字符串 string (array) - 转换为数组 array (object) - 转换为对象 object (unset) - 转换为 NULL (PHP 5)
    if(!$isAllVal){ //类型处理
        switch($type){
            case 'a': // 数组
                $val = (array)$val;
                break;
            case 'd': // 数字
            case 'f': // 浮点
                $defVal = $type=='d'?(int)$defVal:(float)$defVal;
                if ($val==='' || !is_numeric($val)) {$val = $defVal; break;}
                if($type=='d'){ //php_32位或32位系统有溢出
                    if(PHP_INT_SIZE === 8 || ($val>=-2147483648 && $val<=2147483647)) $val = (int)$val;
                }else{
                    $val = (float)$val;
                    if($scale) $val = round($val, $scale);
                }
                if($max!==null && $min!==null) $val = $min<=$val && $val<=$max ? $val : $defVal;
                elseif($max!==null) $val = $val<=$max ? $val : $defVal;
                elseif($min!==null) $val = $min<=$val ? $val : $defVal;
                break;
            case 'b': // 布尔
                $val = (boolean)$val; break;
            case 'date':// 日期
                $val = Helper::is_date($val) ? $val : $defVal; break;
            case 'ymd':// ymd
                $val = Helper::is_ymd($val) ? $val : $defVal; break;
            case 'his':// his
                $val = Helper::is_his($val) ? $val : $defVal; break;
            case 'json':// json
                $val = Helper::is_json($val) ? $val : $defVal; break;
            case 's':   // 字符串
            default:
                if($val=='') {$val = $defVal; break;}
                $val = (string)$val;
                $len = strlen($val);
                if($max!==null && $min!==null) $val = $min<=$len && $len<=$max ? $val : $defVal;
                elseif($max!==null) $val = $len<=$max ? $val : cutstr($val,$max);
                elseif($min!==null) $val = $min<=$len ? $val : $defVal;
        }
    }
    if(Helper::$validAfter instanceof \Closure){ //验证的后置操作
        $fun = Helper::$validAfter;
        $val = $fun($val);
    }
    return $val;
}
//递归自定方法数组处理 传址
function array_call_func($func, &$data){
    //$r=array();
    foreach ($data as $key => $val) {
        $data[$key] = is_array($val)
            ? array_call_func($func, $val)
            : call_user_func($func, $val);
    }
    return $data;
}