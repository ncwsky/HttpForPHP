<?php
namespace HttpForPHP;

use Workerman\Worker;
/**
 * 连接存活定时器
 */
class WorkerLifetimeTimer
{
    protected $heartbeat_time = 0;
    protected $max_idle_time = 0;
    protected $timerMs = 0; //定时 毫秒
    protected $maxTime = 0;
    protected $microtime = 0;
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

    /**
     * @param int $heartbeat_time
     * @param int $max_idle_time
     * @return WorkerLifetimeTimer
     */
    public static function instance($heartbeat_time = 0, $max_idle_time = 0)
    {
        if (!self::$instance) {
            self::$instance = new self($heartbeat_time, $max_idle_time);
        }

        return self::$instance;
    }

    /**
     * ConnLifetimeTimer constructor.
     * @param int $heartbeat_time
     * @param int $max_idle_time
     */
    public function __construct($heartbeat_time = 0, $max_idle_time = 0)
    {
        //存活心跳定时 允许最大空闲定时
        if ($heartbeat_time == 0) $heartbeat_time = defined('CONN_HEARTBEAT_TIME') ? CONN_HEARTBEAT_TIME : 0;
        if ($max_idle_time == 0) $max_idle_time = defined('CONN_MAX_IDLE_TIME') ? CONN_MAX_IDLE_TIME : 0;

        $this->heartbeat_time = (int)$heartbeat_time;
        $this->max_idle_time = (int)$max_idle_time;

        $this->timerMs = $this->minTime($heartbeat_time, $max_idle_time) * 1000;
        $this->maxTime = max($heartbeat_time, $max_idle_time);
        if ($this->timerMs < 0) $this->timerMs = 0;
    }

    public function run()
    {
        if ($this->timerMs <= 0) return; //未配置定时时间

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
        $msg = ' worker:' . SrvBase::$instance->workerId() . ' timer start ';
        if (SrvBase::$isConsole) SrvBase::safeEcho(date("Y-m-d H:i:s") . $msg . PHP_EOL);

        $this->timerId = SrvBase::$instance->server->tick($this->timerMs, function () {
            //if ($this->microtime == 0) return; //该进程没有任何请求

            $now_microtime = microtime(true);
            $diff = round($now_microtime - $this->microtime);
            if ($diff >= $this->maxTime) { //更新触发时间
                $this->microtime = $now_microtime;
            }
            $max_idle_time = $this->max_idle_time;
            $onIdle = $this->onIdle;
            //存活检查
            try {
                if ($this->heartbeat_time > 0 && $this->onHeartbeat !== null && $diff >= $this->heartbeat_time) {
                    call_user_func($this->onHeartbeat);

                    $msg = date("Y-m-d H:i:s") . ' worker:' . SrvBase::$instance->workerId() . ' heartbeat ';
                    if (SrvBase::$isConsole) SrvBase::safeEcho($msg . PHP_EOL);
                }
            } catch (\Exception $e) {
                Log::write($e->getMessage(), 'onHeartbeat');
                //用于清除定时
                $max_idle_time = 1;
                if ($onIdle === null) $onIdle = function () {};
            } catch (\Error $e) {
                Log::write($e->getMessage(), 'onHeartbeat2');
                $max_idle_time = 1;
                if ($onIdle === null) $onIdle = function () {};
            }
            //空闲处理
            try {
                if ($max_idle_time > 0 && $onIdle !== null && $diff >= $max_idle_time) {
                    call_user_func($onIdle);
                    $this->isIdle = true;

                    $msg = ' worker:' . SrvBase::$instance->workerId() . ' onIdle to close, timer:' . $this->timerId . ' to clear';
                    if (SrvBase::$isConsole) SrvBase::safeEcho(date("Y-m-d H:i:s") . $msg . PHP_EOL);
                    //else \HttpForPHP\Log::write($msg);

                    SrvBase::$instance->server->clearTimer($this->timerId); //空闲时清除定时器
                    $this->timerId = 0;
                }
            } catch (\Exception $e) {
                Log::write($e->getMessage(), 'onIdle');
            }
        });
    }
}