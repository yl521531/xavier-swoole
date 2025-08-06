<?php
/**
 * 参考think-swoole2.0开发
 * author:xavier
 * email:49987958@qq.com
 */

namespace xavier\swoole;

use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;
use Swoole\Table;
use Swoole\Timer;
use think\Error;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use think\Config;
use think\Cache;
use think\Loader;
use xavier\swoole\queue\Task as QueueTask;
use xavier\swoole\queue\Process as QueueProcess;
/**
 * Swoole Http Server 命令行服务类
 */
class Http extends Server
{
    protected $app;
    protected $appPath;
    protected $table;
    protected $monitor;
    protected $cachetable;
    protected $lastMtime;
    protected static $http;
	protected $server_type;
    protected $fieldType = [
        'int'    => Table::TYPE_INT,
        'string' => Table::TYPE_STRING,
        'float'  => Table::TYPE_FLOAT,
    ];

    protected $fieldSize = [
        Table::TYPE_INT    => 4,
        Table::TYPE_STRING => 32,
        Table::TYPE_FLOAT  => 8,
    ];

    /**
     * 架构函数
     * @access public
     */
    public function __construct($host, $port, $mode = SWOOLE_PROCESS, $sockType = SWOOLE_SOCK_TCP)
    {
        $this->server_type = $sockType??Config::get('swoole.server_type');
        switch ($this->server_type) {
            case 'websocket':
                $this->swoole = new WebSocketServer($host, $port, $mode, SWOOLE_SOCK_TCP);
                break;
            case 'mixed': // 同时支持 HTTP 和 WebSocket
                $this->swoole = new WebSocketServer($host, $port, $mode, SWOOLE_SOCK_TCP);
                // 注册 HTTP 回调（4.0+ 中 WebSocketServer 可直接处理 HTTP 请求）
                $this->swoole->on('Request', [$this, 'onRequest']);
                break;
            default:
                $this->swoole = new HttpServer($host, $port, $mode, SWOOLE_SOCK_TCP);
        }
        if ("process"==Config::get('swoole.queue_type')){
            $process=new QueueProcess();
            $process->run($this->swoole);
        }
    }

    public function getSwoole()
    {
        return $this->swoole;
    }

    public function setAppPath($path)
    {
        $this->appPath = $path;
    }

    public function setMonitor($interval = 2, $path = [])
    {
        $this->monitor['interval'] = $interval;
        $this->monitor['path']     = (array)$path;
    }

    public function table(array $option)
    {
        $size        = !empty($option['size']) ? $option['size'] : 1024;
        $this->table = new Table($size);

        foreach ($option['column'] as $field => $type) {
            $length = null;

            if (is_array($type)) {
                list($type, $length) = $type;
            }

            if (isset($this->fieldType[$type])) {
                $type = $this->fieldType[$type];
            }

            $this->table->column($field, $type, isset($length) ? $length : $this->fieldSize[$type]);
        }

        $this->table->create();
    }

    public function option(array $option)
    {
        $swooleLog = ['log_level' => SWOOLE_LOG_ERROR];// 设置日志等级
        $option = array_merge($option, $swooleLog);
        unset($option['host'], $option['port'], $option['mode'], $option['sock_type'], $option['app_path']);
        // 设置参数
        if (!empty($option)) {
            $this->swoole->set($option);
        }
        foreach ($this->event as $event) {
            // 自定义回调
            if (!empty($option[$event])) {
                $this->swoole->on($event, $option[$event]);
            } elseif (method_exists($this, 'on' . $event)) {
                $this->swoole->on($event, [$this, 'on' . $event]);
            }
        }
		if ("socket" == $this->server_type) {
            foreach ($this->event as $event) {
                if (method_exists($this, 'Websocketon' . $event)) {
                    $this->swoole->on($event, [$this, 'Websocketon' . $event]);
                }
            }
        }
    }

    /**
     * 此事件在Worker进程/Task进程启动时发生,这里创建的对象可以在进程生命周期内使用
     *
     * @param $server
     * @param $worker_id
     */
    public function onWorkerStart($server, $worker_id)
    {
        // 应用实例化
        $this->app = new Application($this->appPath);
        $this->app->setSwoole($this->swoole);
        $this->lastMtime = time();
        \think\Hook::listen('swoole_on_woker_start', $worker_id);
        if ($this->table) {
            $this->app['swoole_table'] = $this->table;
        }
        Loader::addClassMap([
            'think\\cache\\driver\\Table' => __DIR__ . '/cache/driver/Table.php',
        ]);

        $this->initServer($server, $worker_id);

        if (0 == $worker_id && $this->monitor) {
            $this->monitor($server);
        }
        //只在一个进程内执行定时任务
        if (0 == $worker_id) {
            $this->timer($server);
        }
    }

    /**
     * 自定义初始化Swoole
     * @param $server
     * @param $worker_id
     */
    public function initServer($server, $worker_id)
    {
        $wokerStart = Config::get('swoole.wokerstart');
        if ($wokerStart) {
            if (is_string($wokerStart) && class_exists($wokerStart)) {
                $obj = new $wokerStart($server, $worker_id);
                $obj->run();
                unset($obj);
            } elseif ($wokerStart instanceof \Closure) {
                $wokerStart($server, $worker_id);
            }
        }
    }

    public function getTable()
    {
        return $this->table;
    }

    /**
     * 文件监控
     *
     * @param $server
     */
    protected function monitor($server)
    {
        $paths = $this->monitor['path'] ?: [APP_PATH];
        $timer = $this->monitor['interval'] ?: 2;

        Timer::tick($timer, function () use ($paths, $server) {
            foreach ($paths as $path) {
                $dir      = new \RecursiveDirectoryIterator($path);
                $iterator = new \RecursiveIteratorIterator($dir);

                foreach ($iterator as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) != 'php') {
                        continue;
                    }

                    if ($this->lastMtime < $file->getMTime()) {
                        $this->lastMtime = $file->getMTime();
                        echo '[update]' . $file . " reload...\n";
                        \think\Hook::listen('swoole_reload_file', $file);
                        $server->reload();
                        return;
                    }
                }
            }
        });
    }

    // 修改 src/Http.php 的 timer 方法
    public function timer($server)
    {
        $timer = Config::get('swoole.timer');
        $interval = intval(Config::get('swoole.interval')) ?: 1000;
        $queueType = Config::get('swoole.queue_type');

        if ($timer) {
            $systimer = Timer::instance();
            // 4.0+ 推荐使用 swoole_timer_tick（支持协程环境）
            swoole_timer_tick($interval, function () use ($systimer, $server) {
                // 在协程中执行定时任务，避免阻塞
                go(function () use ($systimer, $server) {
                    $systimer->run($server);
                });
            });
        }

        if ("task" == $queueType) {
            $task = QueueTask::instance();
            swoole_timer_tick(1000, function () use ($task) {
                go(function () use ($task) {
                    $task->run(); // 协程内执行队列调度
                });
            });
        }
    }

    /**
     * request回调
     * @param $request
     * @param $response
     */
    public function onRequest(SwooleRequest $request, SwooleResponse $response)
    {
        \think\Hook::listen('swoole_on_request', $request);
        $this->app->swooleHttp($request, $response);

    }
	
	/**
     * Message回调
     * @param $server
     * @param $frame
     */
    public function WebsocketonMessage($server, $frame)
    {
        // 执行应用并响应
        $this->app->swooleWebSocket($server, $frame);
    }

    /**
     * Close
     */
    public function WebsocketonClose($server, $fd,$reactorId)
    {
        $data=[$server, $fd,$reactorId];
        $debugclient=Config::get('swoole.debug_client');
        if ($debugclient){
            $debug_client_key=Config::get('swoole.debug_client_key');
            $_fd=Cache::get($debug_client_key);
            if ($_fd==$fd){
                Cache::set($debug_client_key,null);
            }
        }
        \think\Hook::listen('swoole_websocket_on_close',$data);
    }

    public function onTask(HttpServer $serv, $task_id, $fromWorkerId, $data)
    {
        if (is_string($data) && class_exists($data)) {
            $taskObj = new $data;
            if (method_exists($taskObj, 'run')) {
                $taskObj->run($serv, $task_id, $fromWorkerId);
                unset($taskObj);
            }
        }

        if (is_object($data) && method_exists($data, 'run')) {
            $data->run($serv, $task_id, $fromWorkerId);
            unset($data);
        }
        \think\Hook::listen('swoole_on_task', $data);
        if ($data instanceof SuperClosure) {
            return $data($serv, $task_id, $data);
        } else {
            $serv->finish($data);
        }

    }

    public function cachetable()
    {
        $this->cachetable = new CacheTable();
    }

    public function getCacheTable()
    {
        return $this->cachetable;
    }

    public function onFinish(HttpServer $serv, $task_id, $data)
    {
        \think\Hook::listen('swoole_on_finish', $data);
        if ($data instanceof SuperClosure) {
            $data($serv, $task_id, $data);
        }
    }

    protected function exception($response, $e)
    {
        if ($e instanceof \Exception) {
            $handler = Error::getExceptionHandler();
            $handler->report($e);

            $resp    = $handler->render($e);
            $content = $resp->getContent();
            $code    = $resp->getCode();

            $response->status($code);
            $response->end($content);
        } else {
            $response->status(500);
            $response->end($e->getMessage());
        }

        throw $e;
    }

    public function setHttp($http)
    {
        self::$http=$http;
    }

    public static function getHttp()
    {
        return self::$http;
    }
}
