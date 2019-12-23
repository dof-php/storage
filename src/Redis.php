<?php

declare(strict_types=1);

namespace DOF\Storage;

use Closure;

/**
 * API docs: <https://github.com/phpredis/phpredis/blob/develop/README.markdown>
 */
class Redis extends Storage
{
    public function __call(string $method, array $params = [])
    {
        $start = \microtime(true);

        $result = $this->connection()->{$method}(...$params);

        $this->log($method, $start, ...$params);

        return $result;
    }

    public function connectable(float &$delay = null) : bool
    {
        if ($connection = $this->connection()) {
            $start = \microtime(true);
            $ping = $connection->ping();
            $delay = \microtime(true) - $start;

            $this->log('ping', $start, $ping);
            return (true === $ping) || ($ping === '+PONG');
        }

        return false;
    }

    public function get(string $key)
    {
        $start = \microtime(true);
        $result = $this->connection()->get($key);
        $this->log('get', $start, $key);

        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * Re-packing multi for closure callback support
     */
    public function multi(Closure $multi)
    {
        // multi() returns the Redis instance and enters multi-mode.
        // Once in multi-mode, all subsequent method calls return the same object until exec() is called.
        $this->logAfter('multi', function () {
            $this->connection()->multi();
        });

        $multi($this);

        return $this->logAfrer('exec', function () {
            return $this->connection(false)->exec();
        });
    }

    /**
     * Re-packing scan for reference variable passing, or you may need workaround code like this:
     *
     * `\call_user_func_array([$redis, 'scan'], array(&$it, $key, 100));`
     */
    public function scan(&$it, string $match = null, int $limit = 10)
    {
        $start = \microtime(true);

        $result = $this->connection()->scan($it, $match, $limit);

        $this->log(\sprintf('scan %d match %s count %d', $it, $match, $limit), $start);

        return $result;
    }
}
