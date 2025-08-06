<?php
/**
 * 参考think-swoole2.0开发
 * author:xavier
 * email:49987958@qq.com
 */

namespace xavier\swoole;

class WebSocketFrame implements \ArrayAccess
{
    private static $instance=null;
    private $server;
    private $frame;
    private $data;

    public function __construct($server,$frame)
    {
        $this->server=$server;
        $this->frame=$frame;
		$this->data=null;
		if (isset($this->frame->data))
			$this->data=json_decode($this->frame->data,true);
    }

    public static function getInstance($server=null,$frame=null)
    {
        if (empty(self::$instance)){
            self::$instance=new static($server,$frame);
        }
        return self::$instance;
    }

    public static function destroy()
    {
        self::$instance=null;
    }

    public function getServer()
    {
        return $this->server;
    }
    
    public function getFrame()
    {
        return $this->frame;
    }

    // 修改 src/WebSocketFrame.php 的 getData 方法（假设存在）
    public function getData()
    {
        $frame = $this->frame;
        // 4.0+ 中 $frame->data 直接获取内容，$frame->opcode 表示帧类型
        if ($frame->opcode == SWOOLE_WEBSOCKET_OPCODE_TEXT) {
            return json_decode($frame->data, true); // 文本帧解析
        }
        return $frame->data; // 二进制帧直接返回
    }

    public function getArgs()
    {
        return isset($this->data['arguments'])?$this->data['arguments']:null;
    }

    public function __call($method,$params)
    {
        return call_user_func_array([$this->server,$method],$params);
    }

    public function pushToClient($data,$event=true)
    {
        if ($event){
            $eventname=isset($this->data['event'])?$this->data['event']:false;
            if ($eventname){
                $data['event']=$eventname;
            }
        }
        $this->sendToClient($this->frame->fd,$data);
    }

    public function sendToClient($fd,$data)
    {
        if (is_string($data))
        {
            $this->server->push($fd,$data);
        }else if (is_array($data)){
            $this->server->push($fd,json_encode($data));
        }
    }

    public function pushToClients($data)
    {
        foreach($this->server->connections as $fd)
        {
            $this->sendToClient($fd,$data);
        }
    }

    public function offsetSet($offset, $value): void
    {
        $this->data[$offset]=$value;
    }

    public function offsetExists($offset) :bool
    {
        return $this->data[$offset]??false;
    }

    public function offsetUnset($offset):void
    {
       unset($this->data[$offset]);
    }

    public function offsetGet($offset) :mixed
    {
        return isset($this->data[$offset])?$this->data[$offset]:null;
    }
}