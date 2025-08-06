<?php
/*
 * CopyRight  : (C)2016-2023 iPiaoBo.cn
 * Document   : CoroutineHttpClient.php
 * Created on : 2025/8/6 11:36
 * Author     : i漂泊(www.ipiaobo.cn)
 * Description: This is NOT a freeware, use is subject to license terms.
 *              这即使是一个免费软件,使用时也请遵守许可证条款,得到当时人书面许可.
 *              未经书面许可,不得翻版,翻版必究;版权归属 i漂泊;
 */
namespace xavier\swoole\Component;

use Swoole\Coroutine\Http\Client;

class CoroutineHttpClient
{
    private $client;
    private $host;
    private $port;

    public static function instance(string $url)
    {
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? $parsed['port'] : 80;
        $ssl = isset($parsed['scheme']) && $parsed['scheme'] === 'https';

        $instance = new self();
        $instance->host = $host;
        $instance->port = $port;
        $instance->client = new Client($host, $port, $ssl);
        return $instance;
    }

    // 发送 GET 请求（协程非阻塞）
    public function get(string $path, array $params = [])
    {
        $query = http_build_query($params);
        $this->client->get($path . ($query ? "?{$query}" : ''));
        return $this->getResult();
    }

    // 发送 POST 请求
    public function post(string $path, array $data = [])
    {
        $this->client->post($path, $data);
        return $this->getResult();
    }

    private function getResult()
    {
        if ($this->client->statusCode === 200) {
            return $this->client->body;
        }
        throw new \Exception("HTTP request failed: {$this->client->statusCode}");
    }

    public function __destruct()
    {
        $this->client->close(); // 自动关闭连接
    }
}