<?php
return [
    'name' => 'myphp-Demo', //服务名
    'ip' => '0.0.0.0', //监听地址
    'port' => 6502, //监听地址
    'init_php' => __DIR__ . '/myphp.base.php',
    'setting' => [
        'count' => 1,    // 异步非阻塞CPU核数的1-4倍最合理 同步阻塞按实际情况来填写 如50-100
        #'task_worker_num'=> 10, //异步任务进程数
        #'max_request'=> 500, //最大请求数 默认0 进程内达到此请求重启进程 可能存在不规范的代码造成内存泄露 这里达到一定请求释放下内存
        'stdoutFile' => __DIR__ . '/myphp.log', //终端输出
        'pidFile' => __DIR__ . '/myphp.pid',
        'logFile' => __DIR__ . '/myphp.log', //日志文件
        # 'user' => 'www', //设置worker/task子进程的进程用户 提升服务器程序的安全性
    ]
];
