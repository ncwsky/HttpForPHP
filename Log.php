<?php
namespace HttpForPHP;

defined('LOG_PATH') || define('LOG_PATH', __DIR__.'/log');
//日志类
class Log{
	private $handler = null;
	private static $level = 0; //日志级别
	private static $size = 2097152; //日志大小 2M 
	private static $instance = null;
	private static $file = null;
	private static $logs = null;
	private static $errs = null;
	private static $errflag = false;
	private static $dir = '_def'; //当前日志目录
	private static $logDir = null; //日志目录

	private function __construct(){}
	public function __destruct(){
		self::free();
	}
	public static function free(){
	    if(!self::$instance) return;
        if(self::$instance->handler){
            foreach(self::$instance->handler as $handler)
                fclose($handler);
        }
        self::$instance=null;
    }
    //注册异常处理
    public static function register(){
		set_error_handler('Log::UserErr'); // 自定义用户错误处理函数
		set_exception_handler('Log::Exception'); //自定义异常处理
		register_shutdown_function('Log::Err'); //定义PHP程序执行完成后执行的函数
    }
	//初始日志目录
	public static function Init($logDir=null, $level=0, $size=2097152){
		if(!self::$instance) self::$instance = new self();

		self::$logDir = $logDir ? (substr($logDir,-1)==DS?$logDir:$logDir.DS) : LOG_PATH.DS;
        !file_exists(self::$logDir) && mkdir(self::$logDir, 0755, true);
		self::$file = self::$logDir.'log.log';
		self::$instance->handler[self::$dir] = fopen(self::$file,'a');
		self::$level = $level;
		self::$size = $size;
		return self::$instance;
	}
	public static function Dir($dir='_def'){
		if(!self::$instance) self::Init();
		if(self::$logs){ //切换日志时记录上个辅助日志
			$logs = implode('', self::$logs);
			self::write($logs, null);
			self::$logs = null;
		}
		self::$dir = $dir;
		if(!isset(self::$instance->handler[$dir])){
            !file_exists(self::$logDir.$dir) && mkdir(self::$logDir.$dir, 0755, true);
			self::$file = self::$logDir.$dir.'/log.log';
			self::$instance->handler[$dir] = fopen(self::$file,'a');
		}
	}
	public static function DEBUG($msg){
		self::write($msg, 'debug');
	}
	public static function INFO($msg){
		self::write($msg, 'info');
	}
	public static function NOTICE($msg){
		self::write($msg, 'notice');
	}
	public static function WARN($msg){
		self::write($msg, 'warn');
	}
	public static function SQL($msg){
		self::write($msg, 'sql');
	}
	public static function ERROR($msg){
		self::write($msg, 'error');
	}
	/*******************分隔***************************/
	//错误日志记录 用于 register_shutdown_function
	public static function Err(){
		$stack = '';
		if ($e = error_get_last()) {
			self::$errflag=true;
			self::$errs[] = $stack = date('[Y-m-d H:i:s]').'[error] type:'.$e['type'].', line:'.$e['line'].', file:'.$e['file'].', message:'.$e['message']."\n";
		}
		if(self::$errflag){ //主日志记录错误信息
			!IS_CLI && self::$errs[] = Log::REQ();
			$logs = implode('', self::$errs);
			self::write($logs, '_def');
			self::$errs = null;
		}
		if(self::$logs){ //辅助日志记录
			$logs = implode('', self::$logs);
			self::write($logs, null);
			self::$logs = null;
		}
		if(!IS_CLI && SRV_DEBUG && $e){
			ob_end_clean();
			exit('<pre style="color:#c10;">'.$stack.'</pre>');
		}
	}
	//自定义错误记录 用于 set_error_handler
	public static function UserErr($errno, $errstr, $errfile, $errline){
		$level = 'info'; $debug=true; $stack = '';
		switch ($errno){
            case E_ERROR:
            case E_USER_ERROR:
                $level = 'error';
                break;
            case E_WARNING:
            case E_USER_WARNING:
                $level = 'warn';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $level = 'notice'; //$debug = false;
                break;
            default:
                $debug = false;
        }
        if($debug){
            //throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
            $debugInfo = debug_backtrace();
            $stack = "\n[\n";
            foreach($debugInfo as $key => $val){
                if(array_key_exists("function", $val)){
                    $stack .= "function:" . $val["function"];
                }
                if(array_key_exists("file", $val)){
                    $stack .= ",file:" . $val["file"];
                }
                if(array_key_exists("line", $val)){
                    $stack .= ",line:" . $val["line"];
                }
                $stack .= "\n";
            }
            $stack .= "]";
        }
        self::write('errno:'.$errno.', line:'.$errline.', file:'.$errfile.', message:'.$errstr .$stack, $level);
	}

    /** 自定义异常记录 用于 set_exception_handler
     * @param \Exception $e
     * @param bool $out
     */
	public static function Exception($e, $out=true){
		$err = $e->getMessage()."\n".'line:'.$e->getLine().', file:'.$e->getFile()."\n".$e->getTraceAsString();
		if(IS_CLI || !$out){
		    self::WARN($err);
		    return;
        }
        self::$errflag=true;
        self::$errs[] = date('[Y-m-d H:i:s]').'[error] '.$err."\n";
        // 发送404信息
        //header('HTTP/1.1 404 Not Found');
        //header('Status:404 Not Found');
		if(SRV_DEBUG) echo '<pre>'.$err.'</pre>';
	}
	public static function miniREQ(){
        $postStr = file_get_contents("php://input");
		$_srv = 'Request: '.$_SERVER['SERVER_PROTOCOL'].' '.$_SERVER['HTTP_HOST'].' '.$_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'].' '.date('Y-m-d H:i:s',$_SERVER['REQUEST_TIME'])."\n"
		.(isset($_SERVER['QUERY_STRING'])?'Query_String: '. urldecode($_SERVER['QUERY_STRING']) ."\n":'')
		.'Remote: '.$_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'].(empty($_SERVER['HTTP_X_REAL_IP'])?'':'('.$_SERVER['HTTP_X_REAL_IP'].')')."\n";
		$post = isset($_POST)?"POST: ".toJson($_POST):''; //."\n".file_get_contents('php://input')

		return $_srv."\n".$post.($postStr?"HTTP_RAW_POST_DATA: ".substr($postStr,0,200):"");
	}
	//返回请求信息
	public static function REQ(){
		$postStr = file_get_contents("php://input");
		$_srv = 'Request: '.$_SERVER['SERVER_PROTOCOL'].' '.$_SERVER['HTTP_HOST'].' '.$_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'].' '.date('Y-m-d H:i:s',$_SERVER['REQUEST_TIME'])."\n"
		.(isset($_SERVER['QUERY_STRING'])?'Query_String: '. urldecode($_SERVER['QUERY_STRING']) ."\n":'')
		.(isset($_SERVER['HTTP_ACCEPT'])?'Http_Accept: '.$_SERVER['HTTP_ACCEPT']."\n":'')
		.(isset($_SERVER['HTTP_REFERER'])?'Http_Referer: '.$_SERVER['HTTP_REFERER']."\n":'')
		.(isset($_SERVER['HTTP_USER_AGENT'])?'Http_User_Agent: '.$_SERVER['HTTP_USER_AGENT']."\n":'')
		.(isset($_SERVER['HTTP_COOKIE'])?'Http_Cookie: '.$_SERVER['HTTP_COOKIE']."\n":'')
		.'Remote: '.$_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'].(empty($_SERVER['HTTP_X_REAL_IP'])?'':'('.$_SERVER['HTTP_X_REAL_IP'].')')."\n";

		$post = isset($_POST)?"POST: ".toJson($_POST)."\n":'';
		return $_srv."\n".$post.($postStr?"HTTP_RAW_POST_DATA: ".substr($postStr,0,200):"");
	}
	//日志记录等级判断
	private static function _level($level){
		$lVal = 2; //日志记录等级值
		switch(strtolower($level)){
			case 'trace':
				$lVal = 0;break; //追踪
			case 'debug':
				$lVal = 1;break; //调试
			case 'info':
				$lVal = 2;break; //信息
			case 'notice':
				$lVal = 3;break; //通知
			case 'warn':
				$lVal = 4;break; //警告
            case '_def':
			case 'error':
				$lVal = 5;break; //错误
			case 'sql':
				$lVal = 10;break; //sql语句
		}
		return self::$level > $lVal ? false : true;
	}
	//记录日志 建议优先使用
	public static function trace($msg,$level='trace'){
		if(IS_CLI){
			self::write($msg, $level);
		}else{
			if(!self::_level($level)) return false;
            if(!is_scalar($msg)) $msg = toJson($msg);
			self::$logs[] = date('[Y-m-d H:i:s]').'['.$level.'] '.$msg."\r\n";
		}
	}
	//写入日志
	public static function write($msg,$level='trace',$file=null){
		if(!self::_level($level)) return false;
        if(!is_scalar($msg)) $msg = toJson($msg);
		$fp = null;
		if(!$file){
            if(isset(self::$instance->handler)) {
                $fp = self::$instance->handler[$level=='_def'?'_def':self::$dir];
            }
            $file = LOG_PATH.'/log.log';
            if(self::$file){
                $file = $level=='_def'?self::$logDir.'log.log':self::$file;
            }
		}
		//日志超过配置大小则备份并重新生成
		if(is_file($file) && self::$size <= filesize($file) ){
			//rename($file, dirname($file).'/'.date('YmdHis').'.log');
			copy($file, dirname($file).'/'.date('YmdHis').'.log');
			if($fp && flock($fp, LOCK_EX | LOCK_NB)) { // 进行排它型锁定 加上LOCK_NB不会堵塞
				ftruncate($fp, 0); // 截断文件 file
				flock($fp, LOCK_UN);    // 释放锁定
			} else {
				file_put_contents($file, '', LOCK_EX | LOCK_NB);
			}
			clearstatcache(true, $file);
		}
		if($level && $level!='_def') $msg = '['.date('Y-m-d H:i:s').']['.$level.'] '.$msg."\r\n";
		$fp ? fwrite($fp, $msg, strlen($msg)+1) : file_put_contents($file, $msg, FILE_APPEND);
    }
}