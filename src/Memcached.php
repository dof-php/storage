<?php

declare(strict_types=1);

namespace DOF\Storage;

use DOF\Storage\Storage;

class Memcached extends Storage
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
        $start = \microtime(true);
        $stats = \count($this->connection()->getStats());
        $delay = \microtime(true) - $start;

        $this->log('getStats', $start, $key);

        return $stats > 0;
    }

    public function get(string $key)
    {
        $start = \microtime(true);

        $result = $this->connection()->get($key);

        $this->log('get', $start, $key);

        if ($this->connection()->getResultCode() === \Memcached::RES_NOTFOUND) {
            return null;
        }

        return $result;
    }

    public function exists(string $key, &$value) : bool
    {
        $start = \microtime(true);

        $value = $this->connection()->get($key);

        $this->log('get', $start, $key);

        // https://www.php.net/manual/en/memcached.get.php
        if ($this->connection()->getResultCode() === \Memcached::RES_NOTFOUND) {
            $value = null;
            return false;
        }

        return true;
    }
}
