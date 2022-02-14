<?php

/**
 * 连接存活定时器
 */
class ConnLifetimeTimer
{
    protected $heartbeat_time = 0;
    protected $max_idle_time = 0;
    protected $timerMs = 0; //定时 毫秒
    protected $maxTime = 0;
    protected $microtime = 0;
    protected $server = null;
    protected $isIdle = false;
    protected $timerId = 0;
    protected static $instance;
    /**
     * @var callable
     */
    public $onHeartbeat = null;
    /**
     * @return bool
     * @var callable
     */
    public $onIdle = null;

    public static function instance($server, $heartbeat_time = 0, $max_idle_time = 0)
    {
        if (!self::$instance) {
            self::$instance = new self($server, $heartbeat_time, $max_idle_time);
        }

        return self::$instance;
    }

    /**
     * ConnLifetimeTimer constructor.
     * @param Worker2|swoole_server $server
     * @param int $heartbeat_time
     * @param int $max_idle_time
     */
    public function __construct($server, $heartbeat_time = 0, $max_idle_time = 0)
    {
        //存活心跳定时 允许最大空闲定时
        if ($heartbeat_time == 0) $heartbeat_time = defined('CONN_HEARTBEAT_TIME') ? CONN_HEARTBEAT_TIME : 0;
        if ($max_idle_time == 0) $max_idle_time = defined('CONN_MAX_IDLE_TIME') ? CONN_MAX_IDLE_TIME : 0;

        $this->server = $server;
        $this->heartbeat_time = (int)$heartbeat_time;
        $this->max_idle_time = (int)$max_idle_time;

        $this->timerMs = $this->minTime($heartbeat_time, $max_idle_time) * 1000;
        $this->maxTime = max($heartbeat_time, $max_idle_time);
        if ($this->timerMs < 0) $this->timerMs = 0;
    }

    public function run()
    {
        $this->isIdle = false;
        $this->microtime = microtime(true);

        if (!$this->timerId) { //重启定时
            $this->runTimer();
        }
    }

    protected function minTime($heartbeat_time, $max_idle_time)
    {
        if ($max_idle_time <= 0) {
            return $heartbeat_time;
        }
        if ($heartbeat_time <= 0) {
            return $max_idle_time;
        }
        return min($heartbeat_time, $max_idle_time);
    }

    protected function runTimer()
    {
        if ($this->timerMs <= 0) return; //未配置定时时间

        if (SrvBase::$isConsole) echo date("Y-m-d H:i:s") . ' worker:' . $this->server->worker_id . ' timer start ' . PHP_EOL;

        $this->timerId = $this->server->tick($this->timerMs, function () {
            //if (SrvBase::$isConsole) echo '-----> timer ' . microtime(true) . PHP_EOL;
            //if ($this->microtime == 0) return; //该进程没有任何请求

            $now_microtime = microtime(true);
            $diff = round($now_microtime - $this->microtime);
            if ($diff >= $this->maxTime) { //更新触发时间
                $this->microtime = $now_microtime;
            }
            //存活检查
            try {
                if ($this->heartbeat_time > 0 && $this->onHeartbeat !== null && $diff >= $this->heartbeat_time) {
                    call_user_func($this->onHeartbeat, $this);

                    $msg = date("Y-m-d H:i:s") . ' worker:' . $this->server->worker_id . ' heartbeat ';
                    if (SrvBase::$isConsole) echo $msg . PHP_EOL;
                }
            } catch (Exception $e) {
                Log::write($e->getMessage(), 'onHeartbeat');
            }
            //空闲处理
            try {
                if ($this->max_idle_time > 0 && $this->onIdle !== null && $diff >= $this->max_idle_time) {
                    call_user_func($this->onIdle, $this);
                    $this->isIdle = true;

                    $msg = date("Y-m-d H:i:s") . ' worker:' . $this->server->worker_id . ' onIdle to close, timer:' . $this->timerId . ' to clear';
                    if (SrvBase::$isConsole) echo $msg . PHP_EOL;
                    //else Log::write($msg, 'onIdle');

                    $this->server->clear($this->timerId); //空闲时清除定时器
                    $this->timerId = 0;
                }
            } catch (Exception $e) {
                Log::write($e->getMessage(), 'onIdle');
            }
        });
    }
}