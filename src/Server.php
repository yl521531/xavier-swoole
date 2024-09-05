<?php
/**
 * 参考think-swoole2.0开发
 * author:xavier
 * email:49987958@qq.com
 */
namespace xavier\swoole;

use Swoole\Http\Server as HttpServer;
use Swoole\Server as SwooleServer;
use Swoole\Websocket\Server as Websocket;
use xavier\swoole\queue\Process as QueueProcess;
/**
 * Swoole Server扩展类
 */
abstract class Server
{
    /**
     * Swoole对象
     * @var object
     */
    protected $swoole;

    /**
     * SwooleServer类型
     * @var string
     */
    protected $serverType = 'socket';

    /**
     * Socket的类型
     * @var int
     */
    protected $sockType = SWOOLE_SOCK_TCP;

    /**
     * 运行模式
     * @var int
     */
    protected $mode = SWOOLE_PROCESS;

    /**
     * 监听地址
     * @var string
     */
    protected $host = '127.0.0.1';

    /**
     * 监听端口
     * @var int
     */
    protected $port = 9501;

    /**
     * 配置
     * @var array
     */
    protected $option = [];

    /**
     * 支持的响应事件
     * @var array
     */
    protected $event = ['Start', 'Shutdown', 'WorkerStart', 'WorkerStop', 'WorkerExit', 'Connect', 'Receive', 'Packet', 'Close', 'BufferFull', 'BufferEmpty', 'Task', 'Finish', 'PipeMessage', 'WorkerError', 'ManagerStart', 'ManagerStop', 'Open', 'Message', 'HandShake', 'Request'];

    /**
     * 架构函数
     * @access public
     */
    public function __construct($host= '0.0.0.0', $port= 9501, $mode = SWOOLE_PROCESS, $sockType = SWOOLE_SOCK_TCP)
    {
        $this->port=$port;
        $this->host=$host;
        $this->mode=$mode;
        $this->sockType=$sockType;
        // 实例化 Swoole 服务
        switch ($this->serverType) {
            case 'socket':
                $this->swoole = new Websocket($this->host, $this->port, $this->mode, $this->sockType);
                break;
            case 'http':
                $this->swoole = new HttpServer($this->host, $this->port, $this->mode, $this->sockType);
                break;
            default:
                $this->swoole = new SwooleServer($this->host, $this->port, $this->mode, $this->sockType);
        }

        // 设置参数
        if (!empty($this->option)) {
            $this->swoole->set($this->option);
        }

        // 设置回调
        foreach ($this->event as $event) {
            if (method_exists($this, 'on' . $event)) {
                $this->swoole->on($event, [$this, 'on' . $event]);
            }
        }

        // 初始化
        $this->init();

        if ("process"==\think\Config::get('swoole.queue_type')){
            $process=new Process();
            $process->run($this->swoole);
        }

        // 启动服务
        //$this->swoole->start();
    }

    protected function init()
    {
    }

    /**
     * 魔术方法 有不存在的操作的时候执行
     * @access public
     * @param string $method 方法名
     * @param array $args 参数
     * @return mixed
     */
    public function __call($method, $args)
    {
        call_user_func_array([$this->swoole, $method], $args);
    }
}
