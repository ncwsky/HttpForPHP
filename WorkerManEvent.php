<?php
namespace HttpForPHP;
defined('ASYNC_NAME') || define('ASYNC_NAME', 'async');

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class WorkerManEvent{
    //有新的连接进入时， $fd 是连接的文件描述符
    public static function onConnect(TcpConnection $connection){
        $fd = $connection->id;
    }
    //接收到数据时回调此函数
    public static function onReceive(TcpConnection $connection, Request $req){
        //重置
        $_COOKIE = $req->cookie();
        $_FILES = $req->file();
        $_GET = $req->get();
        $_POST = $req->post();
        $_REQUEST = array_merge($_GET, $_POST);

        $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
        if($xRealIp=$req->header('x-real-ip')){
            $_SERVER['HTTP_X_REAL_IP'] = $xRealIp;
            $_SERVER['REMOTE_ADDR'] = $xRealIp;
        }
        if($xForwardedFor=$req->header('x-forwarded-for')){
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $xForwardedFor;
        }
        $_SERVER['HTTP_HOST'] = $req->host();
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';#$req->path();
        $_SERVER['REQUEST_METHOD'] = $req->method();
        $_SERVER["REQUEST_URI"] = $req->uri();
        $_SERVER["PATH_INFO"] = $req->path();
        $_SERVER['QUERY_STRING'] = $req->queryString();
        #$_SERVER['DOCUMENT_ROOT'] = dirname($_SERVER['SCRIPT_FILENAME']);
        #$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';
        #Log::DEBUG('[http]'.toJson($_REQUEST));
        #Log::DEBUG('[http_srv]'.toJson($_SERVER));

        //ip验证
        if(!verify_ip()){
            Log::trace('[http]'.Helper::getIp().urldecode(http_build_query($_REQUEST)));
            $connection->send(Helper::toJson(Helper::fail('ip fail')));
            return;
        }

        if (Q(ASYNC_NAME .'%d')==1) { //异步任务
            $data = [
                '_COOKIE'=>$_COOKIE,
                '_FILES'=>$_FILES,
                '_GET'=>$_GET,
                '_POST'=>$_POST,
                '_REQUEST'=>$_REQUEST,
                '_SERVER'=>$_SERVER,
                'header'=>$req->header(),
                'rawbody'=>$req->rawBody()
            ];
            $task_id = WorkerManSrv::$instance->task($data); #
            $response = new \Workerman\Protocols\Http\Response(200, [
                'Content-Type'=>'application/json; charset=utf-8'
            ]);
            if($task_id===false){
                $response->withBody(toJson(Helper::fail('异步任务调用失败:'.WorkerManSrv::err())));
            }else{
                $response->withBody(toJson(Helper::ok(['task_id'=>$task_id])));
            }
            $connection->send($response);
        } else {
            $content = WorkerManSrv::$instance->phpRun($req, $connection);
            if(true!==$content){ #使用默认处理方式
                $code = 200;
                $header = ['Content-Type'=>'application/json; charset=utf-8'];
                $code = isset(Helper::$httpCodeStatus[$code]) ? $code : 200;
                // 发送状态码
                $response = new \Workerman\Protocols\Http\Response($code);
                // 发送头部信息
                $response->withHeaders($header);
                // 发送内容
                $response->withBody(is_string($content) ? $content : toJson($content));
                $connection->send($response);
            }
        }
    }
    //客户端连接关闭事件
    public static function onClose(TcpConnection $connection){

    }
    //当连接的应用层发送缓冲区满时触发
    public static function onBufferFull(TcpConnection $connection){
        //echo "bufferFull and do not send again\n";
        $connection->pauseRecv(); //暂停接收
    }
    //当连接的应用层发送缓冲区数据全部发送完毕时触发
    public static function onBufferDrain(TcpConnection $connection){
        //echo "buffer drain and continue send\n";
        $connection->resumeRecv(); //恢复接收
    }
    //异步任务 在task_worker进程内被调用
    public static function onTask($task_id, $src_worker_id, $data){
        //重置
        $_COOKIE = $data['_COOKIE'];
        $_FILES = $data['_FILES'];
        $_GET = $data['_GET'];
        $_POST = $data['_POST'];
        $_REQUEST = $data['_REQUEST'];
        $_SERVER = $data['_SERVER'];
        #逻辑处理
        $content = WorkerManSrv::$instance->phpRun($data);

        if(WorkerManSrv::$isConsole) Worker::safeEcho('AsyncTask Finish, worker_id:'.$src_worker_id.', task_id:' . $task_id . ', result:'. (is_string($content) ? $content : toJson($content)). PHP_EOL);
        return true;
    }
}