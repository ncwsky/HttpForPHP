<?php
namespace HttpForPHP;

class SwooleEvent{
    public static function onRequest(\swoole_http_request $request, \swoole_http_response $response){
        $_SERVER = array_change_key_case($request->server,CASE_UPPER);
        $_COOKIE = $_FILES = $_REQUEST = $_POST = $_GET = [];
        if($request->cookie) $_COOKIE = &$request->cookie;
        if($request->files) $_FILES = &$request->files;
        if($request->get) $_GET = &$request->get;
        if($request->post) $_POST = &$request->post;
        $_REQUEST = array_merge($_GET, $_POST);
        foreach ($request->header as $k=>$v){
            $k = ($k == 'content-type' || $k == 'content-length' ? '' : 'HTTP_') . str_replace('-', '_', strtoupper($k));
            $_SERVER[$k] = $v;
        }
        //客户端的真实IP HTTP_X_REAL_IP HTTP_X_FORWARDED_FOR
        if(isset($request->header['x-real-ip']) || isset($request->header['x-forwarded-for'])) {
            Helper::$isProxy = true;
        }

        $_SERVER['DOCUMENT_ROOT'] = dirname(SwooleSrv::$_SERVER['SCRIPT_FILENAME']);
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF'] = '/index.php';
        if (isset($request->server['query_string']) && $request->server['query_string'] !== '') { //request_uri swoole不包含get参数
            $_SERVER["REQUEST_URI"] = $request->server["request_uri"] . '?' . $request->server['query_string'];
        }

        //ip验证
        if (!verify_ip()) {
            Log::trace('[' . $_SERVER['REQUEST_METHOD'] . ']' . Helper::getIp() . ' ' . $_SERVER["REQUEST_URI"] . ($_SERVER['REQUEST_METHOD'] == 'POST' ? PHP_EOL . 'post:' . Helper::toJson($_POST) : ''));

            $response->write(Helper::toJson(Helper::fail('ip fail')));
            $response->end();
            return;
        }
        //Log::trace($_SERVER);

        // 可在myphp::Run之前加上 用于post不指定url时通过post数据判断ca
        //if(!isset($_GET['c']) && isset($_POST['c'])) $_GET['c'] = $_POST['c'];
        //if(!isset($_GET['a']) && isset($_POST['a'])) $_GET['a'] = $_POST['a'];
        if (SwooleSrv::$instance->task_worker_num && isset($_REQUEST[ASYNC_NAME]) && $_REQUEST[ASYNC_NAME]==1) { //异步任务
            $task_id = SwooleSrv::$instance->task([
                '_COOKIE'=>$_COOKIE,
                '_FILES'=>$_FILES,
                '_GET'=>$_GET,
                '_POST'=>$_POST,
                '_REQUEST'=>$_REQUEST,
                '_SERVER'=>$_SERVER,
                'header'=>$request->header,
                'rawbody'=>$request->rawContent()
            ]);
            if($task_id===false){
                $response->write(Helper::toJson(Helper::fail('异步任务调用失败:'.SrvBase::err())));
            }else{
                $response->write(Helper::toJson(Helper::ok(['task_id'=>$task_id])));
            }
            $response->end();
        } else {
            $content = SwooleSrv::$instance->phpRun($request, $response);
            if(true!==$content){ #使用默认处理方式
                $code = 200;
                $header = ['Content-Type'=>'application/json; charset=utf-8'];
                $code = isset(Helper::$httpCodeStatus[$code]) ? $code : 200;
                // 发送http
                SwooleSrv::$instance->httpSend($response, $code, $header, $content);
            }
        }
    }
    //异步任务 在task_worker进程内被调用
    public static function onTask(\swoole_server $server, int $task_id, int $src_worker_id, $data){
        //重置
        $_COOKIE = $data['_COOKIE'];
        $_FILES = $data['_FILES'];
        $_GET = $data['_GET'];
        $_POST = $data['_POST'];
        $_REQUEST = $data['_REQUEST'];
        $_SERVER = $data['_SERVER'];
        #逻辑处理
        $content = SwooleSrv::$instance->phpRun($data);

        if(SwooleSrv::$isConsole) SrvBase::safeEcho("AsyncTask Finish:Connect.task_id=" . $task_id . (is_string($data) ? $data : toJson($data)).' result:'. (is_string($content) ? $content : toJson($content)). PHP_EOL);
        unset($_COOKIE, $_FILES, $_GET, $_POST, $_REQUEST, $_SERVER);
        return true;
    }
}