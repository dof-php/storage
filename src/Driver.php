<?php

declare(strict_types=1);

namespace DOF\Storage;

use Throwable;
use DOF\Util\IS;
use DOF\Util\Str;
use DOF\Util\Arr;

class Driver
{
    const LIST = [
        'mysql' => \DOF\Storage\MySQL::class,
        'redis' => \DOF\Storage\Redis::class,
        'memcached' => \DOF\Storage\Memcached::class,
    ];

    public static function format(string $driver) : string
    {
        switch (\strtolower($driver)) {
            case 'mysql':
                return 'MySQL';
            case 'redis':
                return 'Redis';
            case 'memcached':
                return 'Memcached';
            default:
                return $driver;
        }
    }

    public static function storage($driver) : ?string
    {
        if (\is_object($driver)) {
            $class = \get_class($driver);
            $const = \join('::', [$class, 'STORAGE']);
            return \defined($const) ? \strtolower($class::STORAGE) : \strtolower(Arr::last(Str::arr($class, '\\')));
        }

        if (IS::namespace($driver) && \in_array($driver, static::LIST)) {
            $const = \join('::', [$driver, 'STORAGE']);
            return \defined($const) ? \strtolower($driver::STORAGE) : \strtolower(Str::arr($driver, '\\'));
        }

        return null;
    }

    public static function name($driver) : ?string
    {
        if (\is_object($driver)) {
            $class = \get_class($driver);
            $const = \join('::', [$class, 'DRIVER']);
            return \defined($const) ? \strtolower($class::DRIVER) : \strtolower(Arr::last(Str::arr($class, '\\')));
        }

        if (IS::namespace($driver) && \in_array($driver, static::LIST)) {
            $const = \join('::', [$driver, 'DRIVER']);
            return \defined($const) ? \strtolower($driver::DRIVER) : \strtolower(Str::arr($driver, '\\'));
        }

        return null;
    }

    public static function support(string $driver) : bool
    {
        return \array_key_exists(\strtolower($driver), static::LIST);
    }

    // Check if a storage driver requires database name before processing its queries
    public static function database(string $driver) : ?bool
    {
        switch ($driver = \strtolower($driver)) {
            case 'mysql':
            case 'redis':
                return true;
        }

        return null;
    }

    // Check if a storage driver requires table name before processing its queries
    public static function table(string $driver) : ?bool
    {
        switch ($driver = \strtolower($driver)) {
            case 'mysql':
                return true;
        }

        return null;
    }

    public static function get(string $driver) : ?string
    {
        return static::LIST[\strtolower($driver)] ?? null;
    }
}
