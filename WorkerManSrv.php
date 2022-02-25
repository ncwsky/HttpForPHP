<?php
namespace HttpForPHP;

defined('SIGTERM') || define('SIGTERM', 15); //中止服务
defined('SIGUSR1') || define('SIGUSR1', 10); //柔性重启
defined('SIGRTMIN') || define('SIGRTMIN', 34); //SIGRTMIN信号重新打开日志文件
if (!class_exists('Error')) { //兼容7.0
    class Error extends \Exception{}
}
use Workerman\Timer;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;

//增加定时处理的tick、after方法
class Worker2 extends Worker{
    public $channles = []; #记录worker用于通信
    public $onTask = null; #task进程回调
    /** 自定义间隔时钟
     * @param int $msec 毫秒
     * @param callable $callback
     * @param array $args
     * @return bool|int
     */
    public function tick($msec, $callback, $args=[]){
        return Timer::add(round($msec/1000,3), $callback, $args);
    }
    /** 自定义指定时间执行时钟
     * @param int $msec
     * @param callable $callback
     * @param array $args
     * @return bool|int
     */
    public function after($msec, $callback, $args=[]){
        return Timer::add(round($msec/1000,3), $callback, $args, false);
    }

    /**清除定时器
     * @param int $timer_id
     * @return bool
     */
    public function clearTimer($timer_id){
        return Timer::del($timer_id);
    }
}

class WorkerManSrv extends SrvBase {
    public $isWorkerMan = true;
    public $max_request = 0;
    public $request_count = 0;

    public function __construct($config)
    {
        parent::__construct($config);
        $this->max_request = $this->getConfig('setting.max_request', 0);
    }

    /****** 分隔线 ******/
    //主进程回调 仅用于设置进程名
    public function onStart($server){
        //仅允许echo、打印Log、修改进程名称
        $this->setProcessTitle($this->serverName().'-master');
        //此回调有错误时 可能不会有主进程 只有通过管理进程进行结束
        return true;
    }
    /**
     * @var Worker2 $server
     */
    public $server;
    public static $workers = null; //记录所有进程
    public static $taskWorker = null;
    public static $taskAddr = '';
    public static $chainWorker = null;
    public static $chainSocketFile = '';
    public static $fdConnection = null;

    /** 此事件在Worker进程启动时发生 这里创建的对象可以在进程生命周期内使用 如mysql/redis...
     * @param Worker2 $worker
     #* @param int $worker_id [0-$worker_num)区间内的数字
     * @return bool
     */
    final public function _onWorkerStart(Worker2 $worker){
        $worker_id = $worker->id;
        $this->request_count = 0; //重置请求统计数
        #引入框架配置
        $this->initMyPhp();
        self::$_SERVER = $_SERVER; //存放初始的$_SERVER

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
    }

    //reloadable为false时 可以此重载回调重新载入配置等操作
    public function onWorkerReload(Worker2 $worker){
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
        if($this->getConfig('setting.task_worker_num', 0)){
            //创建进程通信服务
            $this->chainWorker();
        }

        //监听1024以下的端口需要root权限
        $this->server = new Worker2(self::TYPE_HTTP.'://'.$this->ip.':'.$this->port, $context);
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
        $server->onError = [$this, '_onWorkerError'];

        //主进程回调
        $this->onStart($server);

        //绑定事件
        $server->onMessage = ['\HttpForPHP\WorkerManEvent', 'onReceive'];

        $server->onTask = null;
        if ($this->task_worker_num && !self::$taskWorker) { //启用了
            $server->onTask = function ($task_id, $src_worker_id, $data){
                return WorkerManEvent::OnTask($task_id, $src_worker_id, $data);
            };

            $taskPort = $this->getConfig('setting.task_port',  $this->port+100);
            self::$taskAddr = "127.0.0.1:".$taskPort;
            //创建异步任务进程
            $taskWorker = new Worker2('frame://'.self::$taskAddr);
            $taskWorker->ip = '127.0.0.1';
            $taskWorker->port = $taskPort;
            $taskWorker->user = $this->getConfig('setting.user', '');
            $taskWorker->name = $server->name.'_task';
            $taskWorker->count = $this->task_worker_num; #unix://不支持多worker进程
            $taskWorker->request_count = 0; //重置请求统计数
            //初始进程事件绑定
            $taskWorker->onWorkerStart = [$this, 'childWorkerStart'];
            if(!$this->getConfig('setting.reloadable', true)) { //不自动重启进程的reload处理
                //如重置载入配置
                $taskWorker->onWorkerReload = [$this, 'onWorkerReload'];
            }
            //当客户端的连接上发生错误时触发
            $taskWorker->onError = [$this, '_onWorkerError'];
            $taskWorker->onConnect = function(TcpConnection $connection) use ($taskWorker){
                $connection->send($taskWorker->id); //返回进程id
            };
            $taskWorker->onMessage = function ($connection, $data) use ($taskWorker) {
                static $request_count = 0;
                if ($this->server->onTask) {
                    $src_worker_id = unpack('n', $data)[1];
                    $data = unserialize(substr($data, 2));
                    call_user_func($this->server->onTask, $taskWorker->id, $src_worker_id, $data);
                    // 请求数达到xxx后退出当前进程，主进程会自动重启一个新的进程
                    if ($this->max_request > 0 && ++$request_count > $this->max_request) {
                        \Workerman\Worker::stopAll();
                    }
                }
            };
            self::$taskWorker = $taskWorker;
        }
        #重载、结束时销毁处理
        Worker::$onMasterReload = Worker::$onMasterStop = function (){
            self::$chainSocketFile && file_exists(self::$chainSocketFile) && @unlink(self::$chainSocketFile);
        };
    }
    public static $remoteConnection = null;
    //连接到内部通信服务
    protected function chainConnection($worker){
        if(!$this->task_worker_num) return;

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
    public function childWorkerStart(Worker2 $worker){
        $this->chainConnection($worker);

        $worker_id = $worker->id;
        #引入框架配置
        $this->initMyPhp();
        Worker::safeEcho("init myphp:".$worker_id.PHP_EOL);
    }
    //创建进程通信服务
    public function chainWorker(){
        $socketFile = (is_dir('/dev/shm') ? '/dev/shm/' : $this->runDir) . '/' . $this->serverName() . '_chain.sock';
        self::$command == 'start' && file_exists($socketFile) && @unlink($socketFile);

        self::$chainSocketFile = $socketFile;
        $chainWorker = new Worker2('unix://'.$socketFile);
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
    public static function chainTo(Worker2 $worker, $fd, $data){
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

    /**
     * 异步任务
     * @param mixed $data
     * @return bool|int
     */
    public function task($data){
        //创建异步任务连接
        $fp = stream_socket_client("tcp://" . self::$taskAddr, $errno, $errstr, 1);
        if (!$fp) {
            self::err("$errstr ($errno)");
            return false;
        } else {
            $worker_id = $this->server->id;
            $taskId = (int)substr(fread($fp, 10), 4);
            $send_data = serialize($data);
            $len = 4 + 2 + strlen($send_data);
            $send_data = pack('N', $len) . pack('n', $worker_id) . $send_data;
            if (!fwrite($fp, $send_data, $len)) {
                $taskId = false;
            }
            fclose($fp);
            return $taskId;
        }
    }

    public function getHeader($req){
        return is_array($req) ? $req['header'] : $req->header();
    }
    public function getRawBody($req){
        return is_array($req) ? $req['rawbody'] : $req->rawBody();
    }
    /**
     * @param TcpConnection $connection
     * @param $code
     * @param $header
     * @param $content
     */
    public function httpSend($connection, $code, &$header, &$content){
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

    final public function relog(){
        Worker::$logFile && file_put_contents(Worker::$logFile, '', LOCK_EX);
        Worker::safeEcho('['.Worker::$logFile.'] relog ok!'.PHP_EOL);
        return true;
    }

    public function run(&$argv){
        $action = isset($argv[1]) ? $argv[1] : 'start';
        self::$isConsole = array_search('--console', $argv);
        if($action=='--console') $action = 'start';
        $argv[1] = $action; //置启动参数
        self::$command = $action;
        switch($action){
            case 'relog':
                $this->relog();
                break;
            default:
                $this->start();
        }
    }
}