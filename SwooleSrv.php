<?php
namespace HttpForPHP;
/**
 * Class SwooleSrv
 */
class SwooleSrv extends SrvBase {
    protected $mode; //运行模式 单线程模式（SWOOLE_BASE）| 进程模式（SWOOLE_PROCESS）[默认]
    /**
     * SwooleSrv constructor.
     * @param array $config
     */
    public function __construct($config)
    {
        parent::__construct($config);
        $this->mode = SWOOLE_BASE;
        $config = &$this->config;
        //兼容处理
        if (isset($config['setting']['pidFile'])) {
            $config['setting']['pid_file'] = $config['setting']['pidFile'];
            unset($config['setting']['pidFile']);
        }
        if (isset($config['setting']['logFile'])) {
            $config['setting']['log_file'] = $config['setting']['logFile'];
            unset($config['setting']['logFile']);
        }
        if (isset($config['setting']['count'])) {
            $config['setting']['worker_num'] = $config['setting']['count'];
            unset($config['setting']['count']);
        }
        unset($config['setting']['stdoutFile']);
        $this->pidFile = $this->getConfig('setting.pid_file', $this->runDir . '/server.pid');
    }
    //此事件在Server正常结束时发生
    public function onShutdown(\swoole_server $server){
        static::safeEcho($this->serverName() . ' shutdown ' . date("Y-m-d H:i:s") . PHP_EOL);
    }
    //管理进程 这里载入了php会造成与worker进程里代码冲突
    public function _onManagerStart(\swoole_server $server){
        $this->setProcessTitle($this->serverName() . '-manager');

        static::safeEcho($this->serverName() . ' swoole' . SWOOLE_VERSION . ' start ' . date("Y-m-d H:i:s") . PHP_EOL);
        static::safeEcho($this->address . PHP_EOL);
        static::safeEcho('master pid:' . $server->master_pid . PHP_EOL);
        static::safeEcho('manager pid:' . $server->manager_pid . PHP_EOL);
        static::safeEcho('run dir:' . $this->runDir . PHP_EOL);
    }
    //当管理进程结束时调用它
    public function _onManagerStop(\swoole_server $server){
        static::safeEcho('manager pid:' . $server->manager_pid . ' end' . PHP_EOL);
    }
    /** 此事件在Worker进程/Task进程启动时发生 这里创建的对象可以在进程生命周期内使用 如mysql/redis...
     * @param \swoole_server $server
     * @param int $worker_id [0-$worker_num)区间内的数字
     * @return bool
     */
    final public function _onWorkerStart($server, $worker_id){
        #引入框架配置
        $this->initMyPhp();
        self::$_SERVER = $_SERVER; //存放初始的$_SERVER

        if ($worker_id >= $server->setting['worker_num']) {
            swoole_set_process_name($this->serverName()."-{$worker_id}-Task");
        } else {
            $this->setProcessTitle($this->serverName()."-{$worker_id}-Worker");
        }

        $this->onWorkerStart($server, $worker_id);
    }

    //此函数主要用于报警和监控，一旦发现Worker进程异常退出，那么很有可能是遇到了致命错误或者进程CoreDump。通过记录日志或者发送报警的信息来提示开发者进行相应的处理
    /** 当Worker/Task进程发生异常后会在Manager进程内回调此函数
     * @param \swoole_server $server
     * @param int $worker_id 是异常进程的编号
     * @param int $worker_pid 是异常进程的ID
     * @param int $exit_code 退出的状态码，范围是 0～255
     * @param int $signal 进程退出的信号
     */
    final public function _onWorkerError(\swoole_server $server, $worker_id, $worker_pid, $exit_code, $signal){
        $err = '异常进程的编号:'.$worker_id.', 异常进程的ID:'.$worker_pid.', 退出的状态码:'.$exit_code.', 进程退出信号:'.$signal;
        static::safeEcho($err . PHP_EOL);
        //todo 记录日志或者发送报警的信息来提示开发者进行相应的处理
        self::err($err);
    }
    //初始服务
    final public function init(){
        $this->config['setting']['daemonize'] = self::$isConsole ? 0 : 1; //守护进程化;
        if(!isset($this->config['setting']['max_wait_time'])) $this->config['setting']['max_wait_time'] = 3; #进程收到停止服务通知后最大等待时间
        $sockType = SWOOLE_SOCK_TCP; //todo ipv6待测试后加入
        $isSSL = isset($this->config['setting']['ssl_cert_file']); //是否使用的证书
        if($isSSL){
            $sockType =  SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }

        //监听1024以下的端口需要root权限
        $this->server = new \swoole_http_server($this->ip, $this->port, $this->mode, $sockType);
        $this->address = self::TYPE_HTTP;
        $this->address .= '://'.$this->ip.':'.$this->port;

        $server = $this->server;
        //设置服务配置
        $server->set($this->getConfig('setting'));

        //初始事件绑定
        $server->on('Shutdown', [$this, 'onShutdown']);
        $server->on('ManagerStart', function ($server) {
            $this->_onManagerStart($server);
        });
        $server->on('ManagerStop', function ($server) {
            $this->_onManagerStop($server);
        });

        $server->on('WorkerStart', function ($server, $worker_id) {
            $this->_onWorkerStart($server, $worker_id);
        });

        $server->on('WorkerError', function ($server, $worker_id, $worker_pid, $exit_code, $signal) {
            $this->_onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
        });

        //事件
        if ($this->task_worker_num) { //启用了
            $server->on('Task', function ($server, $task_id, $src_worker_id, $data) {
                SwooleEvent::onTask($server, $task_id, $src_worker_id, $data);
            });
        }
        $server->on('Request', function ($request, $response) {
            SwooleEvent::onRequest($request, $response);
        });
    }

    public function workerId(){
        return $this->server->worker_id;
    }
    public function task($data){
        return $this->server->task($data);
    }

    public function getHeader($req){
        return is_array($req) ? $req['header'] : $req->header;
    }
    public function getRawBody($req){
        return is_array($req) ? $req['rawbody'] : $req->rawContent();
    }
    /**
     * @param swoole_http_response $response
     * @param $code
     * @param $header
     * @param $content
     */
    public function httpSend($response, $code, &$header, &$content){
        // 发送状态码
        $response->status($code);
        // 发送头部信息
        foreach ($header as $name => $val) {
            $response->header($name, $val);
        }
        // 发送内容
        if (is_string($content)) {
            $content !== '' && $response->write($content);
        } else {
            $response->write(toJson($content));
        }
        $response->end();
    }
    public function close($fd){
        return $this->server->close($fd);
    }
    public function clientInfo($fd){
        return $this->server->getClientInfo($fd);
    }
    final public function exec(){
        $this->server->start();
    }
    final public function relog(){
        $logFile = $this->getConfig('setting.log_file', $this->runDir .'/server.log');
        if($logFile) file_put_contents($logFile,'', LOCK_EX);
        /*if($pid=self::pid()){
            posix_kill($pid, SIGRTMIN); //34  运行时日志不存在可重新打开日志文件
        }*/
        static::safeEcho('['.$logFile.'] relog ok!'.PHP_EOL);
        return true;
    }
    public function run(&$argv){
        $action = ''; //$action = isset($argv[1]) ? $argv[1] : 'start';
        $allow_action = ['relog', 'reloadTask', 'reload', 'stop', 'restart', 'status', 'start'];
        foreach ($argv as $value) {
            if (in_array($value, $allow_action)) {
                $action = $value;
                break;
            }
        }
        self::$isConsole = array_search('--console', $argv);
        if($action=='' || $action=='--console') $action = 'start';
        switch($action){
            case 'relog':
                $this->relog();
                break;
            case 'reload':
                $this->reload();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'restart':
                $this->stop();
                static::safeEcho("Start ".$this->serverName().PHP_EOL);
                $this->start();
                break;
            case 'status':
                $this->status();
                break;
            case 'start':
                if($this->pid()){
                    static::safeEcho($this->pidFile." exists, ".$this->serverName()." is already running or crashed.".PHP_EOL);
                    break;
                }else{
                    static::safeEcho("Start ".$this->serverName().PHP_EOL);
                }
                $this->start();
                break;
            default:
                static::safeEcho('Usage: '. $this->runFile .' {([--console]|start[--console])|stop|restart[--console]|reload|relog|status}'.PHP_EOL);
        }
    }
}