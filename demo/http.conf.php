<?php
return [
    'name' => 'Yii-Demo', //服务名
    'ip' => '0.0.0.0', //监听地址
    'port' => 6502, //监听地址
    'init_php'=> __DIR__.'/base.php',
    'php_run'=> function($req, \Workerman\Connection\TcpConnection $connection=null) {
        static $request_count;
/*  心跳及空闲示例
        $lifetimeTimer = \HttpForPHP\WorkerLifetimeTimer::instance(3, 15);
        $lifetimeTimer->onHeartbeat = function () { //间隔数据库连接检测
            Yii::$app->db->createCommand('select 1')->queryOne();
            if(\Yii::$app->db_chain->getIsActive()) Yii::$app->db_chain->createCommand('select 1')->queryOne();

            Yii::$app->redis->ping();
            if(Yii::$app->redis3->getIsActive()) Yii::$app->redis3->ping();
        };
        $lifetimeTimer->onIdle = function () { //空闲释放
            Yii::$app->db->close();
            if(\Yii::$app->db_chain->getIsActive()) Yii::$app->db_chain->close();

            Yii::$app->redis->close();
            if(Yii::$app->redis3->getIsActive()) Yii::$app->redis3->close();
        };
        $lifetimeTimer->run();*/

        /**
         * 异步任务时$req是数组
         * @var \Workerman\Protocols\Http\Request|array $req
         */
        ob_start();
        $app = Yii::$app;
        $req_is_array = is_array($req);

        // 设置请求头
        $headers = $req_is_array ? $req['header'] : $req->header();
        if(isset($headers['accept'])){
            $headers['accept'] = str_replace('/xml','/json',$headers['accept']);
        }else{
            $headers['accept'] = '*/*';
        }
        foreach ($headers as $name=>$value){
            $app->request->headers->set($name, $value);
        }
        // 设置请求参数
        $app->request->setHostInfo(null);
        $app->request->setBaseUrl(null);
        $app->request->setScriptUrl(null);
        $app->request->setPathInfo(null);
        $app->request->setUrl(null);
        $app->request->setQueryParams($_GET);
        $app->request->setBodyParams($_POST);
        $app->request->setRawBody($req_is_array ? $req['rawbody'] : $req->rawBody());

        $uri_name = trim($_SERVER["PATH_INFO"],'/');

        try{
            $app->run();
        }catch(\Exception $e){
            $msg = $e->getMessage();
            $failReloadMsg = [
                'MySQL server has gone away',
                'Failed to write to socket', #Failed to write to socket. 0 of 34 bytes written.
                'Failed to read from socket',
                'Error while sending QUERY',
                'read error on connection to', #read error on connection to 192.168.0.186:6379
            ];
            #因m异常断开重启进程
            foreach ($failReloadMsg as $fail){
                if(strpos($msg, $fail)!==false){
                    \HttpForPHP\Log::write(sprintf('line:%s, file:%s, err:%s, trace:%s',$e->getLine(), $e->getFile(), $e->getMessage(), $e->getTraceAsString()), 'err');
                    \Workerman\Worker::stopAll();
                    break;
                }
            }

            if($msg!='Page not found.' && $msg!='页面未找到。'){
                $info = sprintf('method:%s, query_str:%s', $_SERVER['REQUEST_METHOD'], $_SERVER['QUERY_STRING']);

                \HttpForPHP\Log::write(sprintf('line:%s, file:%s, err:%s, trace:%s',$e->getLine(), $e->getFile(), $e->getMessage(), $e->getTraceAsString()), 'err');
            }

            echo \HttpForPHP\Helper::toJson(\HttpForPHP\Helper::fail($e->getCode().':'.$msg));
        }
        $content = ob_get_clean();

        // 常驻服务需要清除信息
        $app->request->getHeaders()->removeAll();
        $app->response->clear();

        if($connection===null){ //是异步任务
            \HttpForPHP\Log::write($content, 'task');
        }else{
            $code = 200;
            $header = ['Content-Type'=>'application/json; charset=utf-8','Access-Control-Allow-Origin'=>'*'];
            if($_SERVER["PATH_INFO"]=='/default/qrcode'){
                if($app->request->get('data')){
                    $header['Content-Type'] = 'image/png';
                    if($app->request->get('down')){
                        $header['Content-Disposition'] = 'attachment;filename=qr.png';
                    }
                }
            }
            $header['X-Req'] = 'demo';
            // 发送状态码
            $response = new \Workerman\Protocols\Http\Response($code);
            // 发送头部信息
            $response->withHeaders($header);
            // 发送内容
            if (is_string($content)) {
                $content !== '' && $response->withBody($content);
            } else {
                $response->withBody(\HttpForPHP\toJson($content));
            }
            $connection->send($response);
        }
        unset($content);

        // 可能存在不规范的代码造成内存泄露 这里达到一定请求释放下内存
        if(++$request_count > 200) {
            // 请求数达到xxx后退出当前进程，主进程会自动重启一个新的进程
            \Workerman\Worker::stopAll();
        }
        return true;
    },
    'setting' => [
        'count' => 20,    // 异步非阻塞CPU核数的1-4倍最合理 同步阻塞按实际情况来填写 如50-100
        #'task_worker_num'=> 10, //异步任务进程数
        'stdoutFile'=> __DIR__ . '/http.log', //终端输出
        'pidFile' => __DIR__ . '/http.pid',
        'logFile' => __DIR__ . '/http.log', //日志文件
        # 'user' => 'www-data', //设置worker/task子进程的进程用户 提升服务器程序的安全性
    ]
];
