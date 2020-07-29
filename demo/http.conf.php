<?php
return [
    'name' => 'Yii', //服务名
    'ip' => '0.0.0.0', //监听地址
    'port' => 6502, //监听地址
    'init_php'=> __DIR__.'/base.php',
    'php_run'=> function(\Workerman\Protocols\Http\Request $req, \Workerman\Connection\TcpConnection $connection=null) {
        static $request_count;
        // 业务处理略
        if(++$request_count > 10000) {
            // 请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
            \Workerman\Worker::stopAll();
        }

        ob_start();
        try{
            /*$_SERVER['DOCUMENT_ROOT'] = dirname($_SERVER['SCRIPT_FILENAME']);
            $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'].'/index.php';*/

            // 常驻服务需要清除信息
            Yii::$app->request->getHeaders()->removeAll();
            Yii::$app->response->clear();
            // 设置请求头
            $headers = $req->header();
            foreach ($headers as $name=>$value){
                Yii::$app->request->headers->add($name, $value);
            }
            // 设置请求参数
            Yii::$app->request->setHostInfo(null);
            Yii::$app->request->setBaseUrl(null);
            Yii::$app->request->setScriptUrl(null);
            Yii::$app->request->setPathInfo(null);
            Yii::$app->request->setUrl(null);
            $_SERVER['REQUEST_METHOD'] = $req->method();
            Yii::$app->request->setQueryParams($req->get());
            Yii::$app->request->setQueryParams($req->get());
            Yii::$app->request->setBodyParams($req->post());
            Yii::$app->request->setRawBody($req->rawBody());

            Yii::$app->run();

             #return ob_get_clean(); #直接返回内容使用json方式输出
        }catch(\Exception $e){
            $msg = $e->getMessage();
            #因mysql断开重启进程
            if(strpos($msg, 'MySQL server has gone away') || strpos($msg, 'Error while sending QUERY')){
                \Workerman\Worker::stopAll();
            }
            if($msg!='页面未找到。'){
                \HttpForPHP\Log::write($e->getCode().':'.$msg.$e->getTraceAsString(), 'err');
            }
            echo \HttpForPHP\Helper::toJson(\HttpForPHP\Helper::fail($e->getCode().':'.$msg));
        }
        $content = ob_get_clean();

        $code = 200;
        $header = ['Content-Type'=>'application/json; charset=utf-8'];
        if($req->path()=='/sms/captcha' && !$req->get('base64')){ #验证码
            $header['Content-Type'] = 'image/png';
        }
        // 发送状态码
        $response = new \Workerman\Protocols\Http\Response($code);
        // 发送头部信息
        $response->withHeaders($header);
        // 发送内容
        $response->withBody(is_string($content) ? $content : \HttpForPHP\toJson($content));
        $connection->send($response);
        return true;
    },
    'setting' => [
        'count' => 1,    // 异步非阻塞CPU核数的1-4倍最合理 同步阻塞按实际情况来填写 如50-100
        'task_worker_num'=> 1, //异步任务进程数
        'stdoutFile'=> __DIR__ . '/stdout.log', //终端输出
        'pidFile' => __DIR__ . '/http.pid',
        'logFile' => __DIR__ . '/http.log', //日志文件
        # 'user' => 'www-data', //设置worker/task子进程的进程用户 提升服务器程序的安全性
    ]
];
