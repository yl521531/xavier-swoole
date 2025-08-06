<?php
/*
 * CopyRight  : (C)2016-2023 iPiaoBo.cn
 * Document   : CoroutineLock.php
 * Created on : 2025/8/6 11:37
 * Author     : i漂泊(www.ipiaobo.cn)
 * Description: This is NOT a freeware, use is subject to license terms.
 *              这即使是一个免费软件,使用时也请遵守许可证条款,得到当时人书面许可.
 *              未经书面许可,不得翻版,翻版必究;版权归属 i漂泊;
 */

namespace xavier\swoole\Component;

use Swoole\Coroutine\Lock;

class CoroutineLock
{
    private static $locks = [];

    // 获取锁（自动创建锁实例）
    public static function get(string $key): Lock
    {
        if (!isset(self::$locks[$key])) {
            self::$locks[$key] = new Lock();
        }
        return self::$locks[$key];
    }

    // 加锁并执行回调
    public static function runWithLock(string $key, \Closure $callback)
    {
        $lock = self::get($key);
        $lock->lock(); // 协程阻塞等待锁
        try {
            return $callback();
        } finally {
            $lock->unlock(); // 确保释放锁
        }
    }
}