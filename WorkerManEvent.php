<?php
namespace HttpForPHP;
defined('ASYNC_NAME') || define('ASYNC_NAME', 'async');

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

class WorkerManEvent{
    //接收到数据时回调此函数
    public static function onReceive(TcpConnection $connection, Request $req){
        static $request_count = 0;
        //重置
        $_SERVER = WorkerManSrv::$_SERVER; //使用初始的server
        $_COOKIE = $req->cookie();
        $_FILES = $req->file();
        $_GET = $req->get();
        $_POST = $req->post();
        $_REQUEST = array_merge($_GET, $_POST);
        $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
        $_SERVER['REQUEST_METHOD'] = $req->method();
        foreach ($req->header() as $k=>$v){
            $k = ($k == 'content-type' || $k == 'content-length' ? '' : 'HTTP_') . str_replace('-', '_', strtoupper($k));
            $_SERVER[$k] = $v;
        }
        //客户端的真实IP
        if($req->header('x-real-ip') || $req->header('x-forwarded-for')) { // HTTP_X_REAL_IP HTTP_X_FORWARDED_FOR
            Helper::$isProxy = true;
        }
        $_SERVER['HTTP_HOST'] = $req->host();
        $_SERVER['DOCUMENT_ROOT'] = dirname($_SERVER['SCRIPT_FILENAME']);
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';#$req->path();
        $_SERVER["REQUEST_URI"] = $req->uri();
        $_SERVER["PATH_INFO"] = $req->path();
        $_SERVER['QUERY_STRING'] = $req->queryString();

        //ip验证
        if (!verify_ip()) {
            Log::trace('[' . $_SERVER['REQUEST_METHOD'] . ']' . Helper::getIp() . ' ' . $_SERVER["REQUEST_URI"] . ($_SERVER['REQUEST_METHOD'] == 'POST' ? PHP_EOL . 'post:' . Helper::toJson($_POST) : ''));
            $connection->send(Helper::toJson(Helper::fail('ip fail')));
            return;
        }

        if (Q(ASYNC_NAME .'%d')==1) { //异步任务
            $task_id = WorkerManSrv::$instance->task([
                '_COOKIE'=>$_COOKIE,
                '_FILES'=>$_FILES,
                '_GET'=>$_GET,
                '_POST'=>$_POST,
                '_REQUEST'=>$_REQUEST,
                '_SERVER'=>$_SERVER,
                'header'=>$req->header(),
                'rawbody'=>$req->rawBody()
            ]);
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
                // 发送http
                WorkerManSrv::$instance->httpSend($connection, $code, $header, $content);
            }
        }

        // 请求数达到xxx后退出当前进程，主进程会自动重启一个新的进程
        if (WorkerManSrv::$instance->max_request > 0 && ++$request_count > WorkerManSrv::$instance->max_request) {
            \Workerman\Worker::stopAll();
        }
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