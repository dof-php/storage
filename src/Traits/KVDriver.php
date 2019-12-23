<?php

declare(strict_types=1);

namespace DOF\Storage\Traits;

use DOF\Util\IS;
use DOF\Util\Str;
use DOF\Util\Arr;
use DOF\Storage\Exceptor\DriverExceptor;

trait KVDriver
{
    public static function option(string $driver, string $key, array $config = []) : ?array
    {
        $kvtype = static::KV;

        if (! ($pool = $config['pool'] ?? [])) {
            throw new DriverExceptor('MISSING_OR_EMPTY_DRIVER_CONNECTION_POOL', \compact('driver', 'kvtype'));
        }

        $conn = null;
        if (($group = ($config['group'] ?? [])) && ($conns = self::group($group, $key)) && ($conn = Arr::partition($conns, $key))) {
        } elseif ($kv = ($config[$kvtype] ?? [])) {
            $conn = Arr::partition($kv, $key);
        } else {
            Arr::partition($pool, $key, $conn);
        }

        if ((! $conn) || (! ($node = $pool[$conn] ?? null))) {
            throw new DriverExceptor('CONNOT_FOUND_VALID_CONNECTION_NODE', \compact('driver', 'key', 'kvtype'));
        }

        switch (\strtolower($driver)) {
            case 'redis':
                $no = \intval($node['dbnum'] ?? 16) - 1;
                $db = Str::partition($key) % $no;
                return ['meta' => ['DATABASE' => $db, 'CONNECTION' => $conn]];
            case 'memcached':
               return ['meta' => ['CONNECTION' => $conn]];
            default:
                return null;
        }

        return null;
    }

    /**
     * Get connections of a group name based on a kv name (queue/cache/...)
     */
    public static function group(array $group, string $key)
    {
        $key = \strtolower($key);
        $res = [];

        foreach ($group as $name => $conns) {
            if (! \is_string($name)) {
                throw new DriverExceptor('INVALID_CONNECTION_GROUP_NAME', ['name' => $name, 'kvtype'=> static::KV]);
            }
            if (Str::start(\strtolower($name), $key)) {
                $len = \mb_strlen($name);
                if (empty($res)) {
                    $res = [$len, $conns];
                } else {
                    list($_len, ) = $res;
                    if ($len > $_len) {
                        $res = [$len, $conns];
                    }
                }
            }
        }

        return $res[1] ?? null;
    }
}
