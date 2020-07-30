<?php
namespace HttpForPHP;

defined('SIGTERM') || define('SIGTERM', 15); //中止服务
defined('SIGUSR1') || define('SIGUSR1', 10); //柔性重启
defined('SIGRTMIN') || define('SIGRTMIN', 34); //SIGRTMIN信号重新打开日志文件

use Workerman\Worker;
use Workerman\Connection\TcpConnection;

class WorkerManSrv {
    use SrvMsg;
    //全局变量存放 仅当前的工作进程有效[参见进程隔离]
    public static $isConsole = false;
    public static $runApp = null;
    public static $runConfig = null;
    protected $config;
    protected $runFile;
    public $runDir;
    protected $pidFile;
    protected $pid;
    protected $address;
    public $port;
    protected $ip;
    public static $instance;
    const TYPE_HTTP = 'http';
    /**
     * SrvBase constructor.
     * @param array $config
     */
    public function __construct($config)
    {
        self::$instance = $this;
        $this->runFile = $_SERVER['SCRIPT_FILENAME'];
        $this->runDir = dirname($this->runFile);
        $this->config = $config;
        $this->pidFile = $this->getConfig('setting.pid_file', $this->runDir .'/server.pid');
        $this->ip = $this->getConfig('ip', '0.0.0.0');
        $this->port = $this->getConfig('port', 7900);
    }
    public function __destruct()
    {
        $timerFile = self::$instance->getConfig('timer_file');
        $shmFile =  $timerFile ? '/dev/shm/'.str_replace(['/','\\'],'',  $timerFile) : '';
        if(file_exists($shmFile)){ //销毁定时内存缓存配置
            @unlink($shmFile);
        }
    }
    public function getConfig($name, $def=''){
        //获取值
        if (false === ($pos = strpos($name, '.')))
            return isset($this->config[$name]) ? $this->config[$name] : $def;
        // 二维数组支持
        $name1 = substr($name, 0, $pos);
        $name2 = substr($name, $pos + 1);
        return isset($this->config[$name1][$name2]) ? $this->config[$name1][$name2] : $def;
    }
    public function serverName(){
        return $this->getConfig('name', basename($this->runFile,'.php'));
    }
    #引入框架代码初始
    protected function initMyPhp(){
        $initPhp = $this->getConfig('init_php', $this->runDir. '/base.php') ;
        if(!is_file($initPhp)){
            throw new \Exception('未配置要引入的运行文件');
        }
        include $initPhp;
    }
    public function phpRun($data, TcpConnection $connection=null){
        $phpRun = $this->getConfig('php_run', $this->runDir. '/base.php');
        if($phpRun instanceof \Closure){
            return $phpRun($data, $connection);
        }
        throw new \Exception('未配置php_run匿名函数');
    }
    final protected function setProcessTitle($title){
        // >=php 5.5
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (extension_loaded('proctitle') && function_exists('setproctitle')) {
            setproctitle($title);
        }
    }

    /****** 分隔线 ******/
    //主进程回调 仅用于设置进程名
    public function onStart($server){
        //仅允许echo、打印Log、修改进程名称
        $this->setProcessTitle($this->serverName().'-master');
        //此回调有错误时 可能不会有主进程 只有通过管理进程进行结束
        return true;
    }
    protected function onWorkerStart($server, $worker_id){
        if($worker_id==0){ //
            //todo 清理上次服务的全局缓存数据
        }
    }
    /**
     * @var Worker $server
     */
    public $server;
    public static $workers = null; //记录所有进程
    public static $taskWorker = null;
    public static $taskAddr = '';
    public static $chainWorker = null;
    public static $chainSocketFile = '';
    public static $fdConnection = null;

    /** 此事件在Worker进程启动时发生 这里创建的对象可以在进程生命周期内使用 如mysql/redis...
     * @param Worker $worker
     #* @param int $worker_id [0-$worker_num)区间内的数字
     * @return bool
     */
    final public function _onWorkerStart(Worker $worker){
        $worker_id = $worker->id;
        #引入框架配置
        $this->initMyPhp();
        #Worker::safeEcho("init myphp:".$worker_id.PHP_EOL);
        #if($worker_id==0 && self::$isConsole) Log::write($_SERVER, 'server');

        //连接到内部通信服务
        $this->chainConnection($worker);

        $this->onWorkerStart($worker, $worker_id);
    }
    //当客户端的连接上发生错误时触发 参见 http://doc.workerman.net/worker/on-error.html
    public function _onWorkerError(TcpConnection $connection, $code, $msg){
        $err = '异常进程的ID:'.$connection->worker->id.', 异常连接的ID:'.$connection->id.', code:'.$code.', msg:'.$msg;
        Worker::safeEcho($err.PHP_EOL);
        //todo 记录日志或者发送报警的信息来提示开发者进行相应的处理
        self::err($err);
        $this->onWorkerError($connection, $code, $msg);
    }
    protected function onWorkerError(TcpConnection $connection, $code, $msg){}

    //reloadable为false时 可以此重载回调重新载入配置等操作
    public function onWorkerReload(Worker $worker){
        //todo
    }
    //初始服务
    final public function init(){
        Worker::$daemonize = self::$isConsole ? false : true; //守护进程化;
        if(isset($this->config['setting']['stdoutFile'])) {
            Worker::$stdoutFile = $this->config['setting']['stdoutFile'];
            unset($this->config['setting']['stdoutFile']);
        }
        if(isset($this->config['setting']['pidFile'])) {
            Worker::$pidFile = $this->config['setting']['pidFile'];
            unset($this->config['setting']['pidFile']);
        }
        if(isset($this->config['setting']['logFile'])) {
            Worker::$logFile = $this->config['setting']['logFile'];
            unset($this->config['setting']['logFile']);
        }
        $context = $this->getConfig('context', []); //资源上下文
        //创建进程通信服务
        $this->chainWorker();

        //监听1024以下的端口需要root权限
        $this->server = new Worker(self::TYPE_HTTP.'://'.$this->ip.':'.$this->port, $context);
        $this->address = self::TYPE_HTTP;
        $this->address .= '://'.$this->ip.':'.$this->port;

        $server = $this->server;

        $server->ip = $this->ip;
        $server->port = $this->port;
        //设置服务配置
        foreach($this->config['setting'] as $k=>$v){
            $server->$k = $v;
        }
        if($server->name=='none'){
            $server->name = $this->serverName();
        }
        if(!empty($context['ssl'])){ // 设置transport开启ssl
            $server->transport = 'ssl';
        }

        //初始进程事件绑定
        $server->onWorkerStart = [$this, '_onWorkerStart'];
        if(!$this->getConfig('setting.reloadable', true)) { //不自动重启进程的reload处理
            //如重置载入配置
            $server->onWorkerReload = [$this, 'onWorkerReload'];
        }
        //当客户端的连接上发生错误时触发
        $server->onError = [$this, 'onWorkerError'];

        //主进程回调
        $this->onStart($server);

        //绑定事件
        $server->onConnect= ['\HttpForPHP\WorkerManEvent', 'onConnect'];
        $server->onMessage = ['\HttpForPHP\WorkerManEvent', 'onReceive'];
        $server->onClose= ['\HttpForPHP\WorkerManEvent', 'onClose'];
        $server->onBufferFull = ['\HttpForPHP\WorkerManEvent', 'onBufferFull'];
        $server->onBufferDrain = ['\HttpForPHP\WorkerManEvent', 'onBufferDrain'];

        $server->onTask = null;
        if ($this->getConfig('setting.task_worker_num', 0) && !self::$taskWorker) { //启用了
            $server->onTask = function ($task_id, $src_worker_id, $data){
                #echo 'taskId:',$task_id,'; src_workerId:',$src_worker_id,PHP_EOL;
                return WorkerManEvent::OnTask($task_id, $src_worker_id, $data);
            };

            $taskPort = $this->getConfig('setting.task_port',  $this->port+100);
            self::$taskAddr = "127.0.0.1:".$taskPort;
            //创建异步任务进程
            $taskWorker = new Worker('frame://'.self::$taskAddr);
            $taskWorker->ip = '127.0.0.1';
            $taskWorker->port = $taskPort;
            $taskWorker->user = $this->getConfig('setting.user', '');
            $taskWorker->name = $server->name.'_task';
            $taskWorker->count = $this->getConfig('setting.task_worker_num', 0); #unix://不支持多worker进程
            //初始进程事件绑定
            $taskWorker->onWorkerStart = [$this, 'childWorkerStart'];
            if(!$this->getConfig('setting.reloadable', true)) { //不自动重启进程的reload处理
                //如重置载入配置
                $taskWorker->onWorkerReload = [$this, 'onWorkerReload'];
            }
            //当客户端的连接上发生错误时触发
            $taskWorker->onError = [$this, 'onWorkerError'];
            $taskWorker->onConnect = function(TcpConnection $connection) use ($taskWorker){
                $connection->send($taskWorker->id); //返回进程id
            };
            $taskWorker->onMessage = function ($connection, $data) use ($taskWorker) {
                $data = unserialize($data);
                $ret = null;
                if($this->server->onTask){
                    call_user_func($this->server->onTask, $taskWorker->id, $this->server->id, $data);
                }
            };
            $taskWorker->listen();
            self::$taskWorker = $taskWorker;
        }
    }
    public static $remoteConnection = null;
    //连接到内部通信服务
    protected function chainConnection($worker){
        //生成唯一id
        $uniqid = self::workerToUniqId($worker->port, $worker->id);
        $worker->uniqid = $uniqid;

        self::$workers[$worker->uniqid] = $worker;

        self::$remoteConnection = new \Workerman\Connection\AsyncTcpConnection('unix://' . self::$chainSocketFile);
        self::$remoteConnection->protocol = '\Workerman\Protocols\Frame';
        self::$remoteConnection->onClose = null; //可加定时重连
        self::$remoteConnection->onConnect = function ($connection) use($uniqid){
            $connection->send(serialize(['a'=>'reg','uniqid'=>$uniqid]));
        };
        self::$remoteConnection->onMessage = function ($connection, $data) use($worker){
            list($fd, $raw) = explode('|', $data, 2);
            $fd = (int)$fd;
            if($fd==-1){ //群发
                foreach ($worker->connections as $conn){
                    $conn->send($raw);
                }
            }else{ //指定
                if(isset($worker->connections[$fd])){
                    $worker->connections[$fd]->send($raw);
                }
            }
        };
        self::$remoteConnection->connect();
    }
    //
    public function childWorkerStart(Worker $worker){
        $this->chainConnection($worker);

        $worker_id = $worker->id;
        #引入框架配置
        $this->initMyPhp();
        Worker::safeEcho("init myphp:".$worker_id.PHP_EOL);
    }
    //创建进程通信服务
    public function chainWorker(){
        $socketFile = $this->runDir.'/'.$this->serverName().'_chain.sock';
        $socketFile = '/dev/shm/' . $this->serverName() . '_chain.sock';
        if (file_exists($socketFile)) {
            @unlink($socketFile);
        }
        self::$chainSocketFile = $socketFile;
        $chainWorker = new Worker('unix://'.$socketFile);
        $chainWorker->user = $this->getConfig('setting.user', '');
        $chainWorker->name = $this->serverName().'_chain';
        $chainWorker->protocol = '\Workerman\Protocols\Frame';
        $chainWorker->channles = []; //记录连接的wokerid
        $chainWorker->onMessage = function ($connection, $data) use ($chainWorker) {
            $data = unserialize($data); // ['a'=>'命令','fd'=>'','uniqid'=>'', 'raw'=>'原始发送数据']
            switch ($data['a']){
                case 'to': //指定转发 fd workerid
                    $client = self::uniqIdToClient($data['fd']);
                    if(!$client) {
                        echo 'fd:'.$data['fd'].' is invalid.',PHP_EOL;
                        return;
                    }
                    $uniqid = self::workerToUniqId($client['local_port'], $client['worker_id']);
                    if(isset($chainWorker->channles[$uniqid])){
                        $chainWorker->channles[$uniqid]->send($client['self_id'].'|'.$data['raw']);
                    }
                    break;
                case 'all': //群发
                    foreach ($chainWorker->channles as $uniqid=>$conn){
                        $conn->send('-1|'.$data['raw']);
                    }
                    break;
                case 'reg': //登记 'uniqid'=>
                    $chainWorker->channles[$data['uniqid']] = $connection;
                    $chainData = self::uniqIdToWorker($data['uniqid']);
                    $msg = 'chain reg from ';
                    if($chainData){
                        echo $msg,'local_port:'.$chainData['local_port'].', self_id:'.$chainData['self_id'],PHP_EOL;
                    }else{
                        echo $msg.'fail',PHP_EOL,PHP_EOL;
                    }
                    break;
            }
        };
        $chainWorker->onClose = function ($connection){};
        self::$chainWorker = $chainWorker;
    }
    //通道通信
    public static function chainTo(Worker $worker, $fd, $data){
        $data = ['a'=>$fd===-1?'all':'to','fd'=>$fd, 'raw'=>$data];
        echo PHP_EOL, 'workerId:'.$worker->id.', name:'.$worker->name.', port:'.$worker->port.', chain:'.( self::$remoteConnection ? 'has':'no'),PHP_EOL, PHP_EOL;
        self::$remoteConnection->send(serialize($data)); //内部通信-消息转发
    }
    /**
     * 通讯地址到 uniqid 的转换
     *
     * @param int $worker_id
     * @param int $local_port
     * @param int $self_id
     * @return string
     */
    public static function clientToUniqId($worker_id, $local_port, $self_id)
    {
        return bin2hex(pack('nnN', $worker_id, $local_port, $self_id));
    }

    /**
     * uniqid 到通讯地址的转换
     *
     * @param string $uniqid
     * @return array
     * @throws \Exception
     */
    public static function uniqIdToClient($uniqid)
    {
        if (strlen($uniqid) !== 16) {
            echo new \Exception("uniqid $uniqid is invalid");
            return false;
        }
        $ret = unpack('nworker_id/nlocal_port/Nself_id', pack('H*', $uniqid));
        return $ret;
    }
    /**
     * worker到 uniqid 的转换
     *
     * @param int $local_port
     * @param int $self_id
     * @return string
     */
    public static function workerToUniqId($local_port, $self_id)
    {
        return bin2hex(pack('nN', $local_port, $self_id));
    }

    /**
     * uniqid 到worker的转换
     *
     * @param string $uniqid
     * @return array
     * @throws \Exception
     */
    public static function uniqIdToWorker($uniqid)
    {
        if (strlen($uniqid) !== 12) {
            echo new \Exception("uniqid $uniqid is invalid");
            return false;
        }
        return unpack('nlocal_port/Nself_id', pack('H*', $uniqid));
    }
    public function workerId(){
        return $this->server->id;
    }
    public function task($data){
        //创建异步任务连接
        #echo 'init task conn',PHP_EOL;
        #$taskConn = new TcpConnection(stream_socket_client( "tcp://".self::$taskAddr));
        #$taskConn->protocol = '\Workerman\Protocols\Frame';
       /* $taskConn = new \Workerman\Connection\AsyncTcpConnection('frame://'.self::$taskAddr);
        $taskConn->taskId = false;
        $taskConn->onMessage = function ($connection, $data) use(&$taskConn){
            $taskConn->taskId = $data;
        };
        $taskConn->send(serialize($data));
        $taskConn->connect();*/

        $fp = stream_socket_client("tcp://".self::$taskAddr, $errno, $errstr, 1);
        if (!$fp) {
            #echo "$errstr ($errno)",PHP_EOL;
            self::err("$errstr ($errno)");
            return false;
        } else {
            $taskId = (int)substr(fread($fp, 10),4);
            $send_data = serialize($data);
            $len = strlen($send_data)+4;
            $send_data = pack('N', $len) . $send_data;
            if(!fwrite($fp, $send_data, $len)){
                $taskId = false;
            }
            fclose($fp);
            return $taskId;
        }
    }
    public function send($fd, $data){
        $connection = $this->getConnection($fd);
        if(!$connection){ //内部通信
            self::chainTo($this->server, $fd, $data);
            return true;
        }
        //只要send不返回false并且网络没有断开，而且客户端接收正常，数据基本上可以看做100%能发到对方的。
        if($connection){
            return false!==$connection->send($data); //true null false
        }
        return false;
    }
    public function close($fd){
        $connection = $this->getConnection($fd);
        if($connection){
            return $connection->close();
        }
        return false;
    }

    /** 获取客户端信息
     * @param $fd
     * @param bool $obj $fd是否是对象
     * @return array|null
     */
    public function clientInfo($fd, $obj=false){
        $connection = $obj ? $fd : $this->getConnection($fd);
        if($connection){
            return [
                'remote_ip'=> $connection->getRemoteIp(),
                'remote_port'=> $connection->getRemotePort(),
                'server_port'=> $connection->worker->port,
            ];
        }
        return null;
    }
    final public function exec(){
        Worker::runAll();
    }
    final public function getConnection($fd){ //仅读取主要服务用于发送消息
        if(self::$fdConnection) return self::$fdConnection ;
        $uniqid = '';
        $client = self::uniqIdToClient($fd);
        if($client) {
            $uniqid = self::workerToUniqId($client['local_port'], $client['worker_id']);
            $fd = $client['self_id'];
        }
        return isset(self::$workers[$uniqid])? self::$workers[$uniqid]->connections[$fd] : null;
        $worker = $uniqid && isset(self::$workers[$uniqid])? self::$workers[$uniqid] : $this->server;
        #$connection = isset($worker->connections[$fd]) ? $worker->connections[$fd] : null;
        return $connection;
    }
    //初始服务之前执行
    protected function beforeInit(){
        //todo
    }
    //初始服务之后执行
    protected function afterInit(){
        //todo
    }
    //reload -g会等所有客户端连接断开后重启 stop -g会等所有客户端连接断开后关闭
    public function start(){
        $this->beforeInit();
        //初始服务
        $this->init();
        $this->afterInit();
        //启动
        $this->exec();
    }
    final public function relog(){
        Worker::$logFile && file_put_contents(Worker::$logFile, '', LOCK_EX);
        Worker::safeEcho('['.Worker::$logFile.'] relog ok!',PHP_EOL);
        return true;
    }
    //检查进程pid是否存在
    public function pid(){
        if(file_exists($this->pidFile) && $pid = file_get_contents($this->pidFile)){
            if(posix_kill($pid, 0)) { //检测进程是否存在，不会发送信号
                return $pid;
            }
        }
        return false;
    }
    public function run(&$argv){
        $action = isset($argv[1]) ? $argv[1] : 'start';
        self::$isConsole = array_search('--console', $argv);
        if($action=='--console') $action = 'start';
        $argv[1] = $action; //置启动参数
        switch($action){
            case 'relog':
                $this->relog();
                break;
            default:
                $this->start();
        }
    }
}