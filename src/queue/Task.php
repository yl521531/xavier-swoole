<?php

namespace xavier\swoole\queue;

use xavier\swoole\Task as SwooleTask;
use think\queue\Worker;

/**
 * 队列异步投递
 * 优点:可以和共享所有Task进程，更有效的充分利用资源
 */
class Task extends Queue
{
    private static $timerlists = [];
    private static $instance = null;

    public function __construct()
    {
        $this->getConfig();
        $this->initTimerLists();
    }

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new static();
            return self::$instance;
        }
        return self::$instance;
    }

    /**
     * 备注:和Process模式不同，这里属于定时执行，每隔一段时间执行一下任务，
     * 如果任务较多，可能存在都被投递到Task进程，
     * 因而推荐采用Process模式，这里可能会造成Task繁忙导致其他进程任务无法消耗
     */
    public function run()
    {
        foreach ($this->config as $key => $val) {
            if ($this->config[$key]["nexttime"] <= time()) {
                $this->config[$key]["nexttime"] = time() + $val['sleep'];
                for ($i = 0; $i < $val['nums']; $i++) {
                    $this->job($key, $val);
                }
            }
        }
    }

    public function initTimerLists()
    {
        if (!empty($this->config))
            foreach ($this->config as $key => $val) {
                $this->config[$key]["nexttime"] = time();
            }
    }

    // 在 src/queue/Task.php 的 job 方法中使用
    public function job($key, $val)
    {
        go(function () use ($key, $val) {
            // 对相同 $key 的任务加锁，避免并发处理
            CoroutineLock::runWithLock("queue_{$key}", function () use ($key, $val) {
                $worker = new Worker();
                $worker->pop($key, $val['delay'], 0, $val['maxTries']);
            });
        });
    }
}
