<?php

declare(strict_types=1);

namespace DOF\Storage;

use PDO;
use Throwable;
use DOF\Util\IS;
use DOF\Util\Format;
use DOF\Util\Validator;
use DOF\Storage\Driver;
use DOF\Storage\Exceptor\ConnectionExceptor;

final class Connection
{
    private static $pool = [];

    public static function get(string $driver, array $config, array $option = [])
    {
        if (! Driver::support($driver)) {
            throw new ConnectionExceptor('CONNECTION_DRIVER_NOT_SUPPORT', \compact('driver'));
        }

        $config = \array_change_key_case($config, CASE_LOWER);
        // $option = \array_change_key_case($option, CASE_UPPER);

        $connection = null;

        // Read Write separation check
        if (\is_string($conn = ($option['meta']['CONNECTION'] ?? null))) {
            $connection = $conn;
        } elseif ($rws = ($option['meta']['RW_SPLIT'] ?? null)) {
            if (! \is_string($rws)) {
                throw new ConnectionExceptor('INVALID_RW_SPLIT_OPTION', \compact('driver', 'rws'));
            }
            $pool = [];
            switch (\strtolower($rws)) {
                case 'ro':
                    $pool = $config['ro'] ?? [];
                    break;
                case 'wo':
                    $pool = $config['wo'] ?? [];
                    break;
                case 'rw':
                    $pool = $config['rw'] ?? [];
                    break;
                default:
                    throw new ConnectionExceptor('UNKNOWN_RWS_OPTION', \compact('driver', 'rws'));
                    break;
            }
            if (empty($pool)) {
                throw new ConnectionExceptor('EMPTY_RWS_POOL', \compact('driver', 'rws'));
            }
            $connection = Rand::get($pool);
        }
        // Other cluster check (master/slave/queue)
        elseif ($rwc = ($option['meta']['RW_CLUSTER'] ?? null)) {
            if (! \is_string($rwc)) {
                throw new ConnectionExceptor('INVALID_RW_CLUSTER_OPTION', \compact('driver', 'rwc'));
            }
            $pool = $config[\strtolower($rwc)] ?? [];
            if (empty($pool)) {
                throw new ConnectionExceptor('EMPTY_CLUSTER_POOL', \compact('driver', 'rwc'));
            }
            $connection = Rand::get($pool);
        }
        // Default connection check
        else {
            $connection = $config['default'] ?? null;
        }

        if (IS::empty($connection)) {
            throw new ConnectionExceptor('NO_CONNECTION_TO_USE', \compact('driver'));
        }

        $conn = self::$pool[$driver][$connection] ?? null;
        if ($conn) {
            return $conn;
        }

        return self::add($driver, $connection, ($config['pool'][$connection] ?? []));
    }

    public static function add(string $driver, string $connection, array $config)
    {
        return self::$pool[$driver][$connection] = \call_user_func_array([static::class, $driver], [$config, $connection]);
    }

    public static function mysql(iterable $config, string $connection = null) : PDO
    {
        $config = IS::collection($config) ? $config : Format::collect($config);

        $host = $config->get('host', '127.0.0.1', ['type' => 'string']);
        $port = $config->get('port', 3306, ['type' => 'pint']);
        $user = $config->get('user', '');
        $pswd = $config->get('pswd', '');
        $charset = $config->get('charset', 'utf8mb4', ['type' => 'string']);
        $dbname  = $config->get('dbname', '', ['type' => 'string']);

        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        if ($dbname) {
            $dsn .= ";dbname={$dbname}";
        }

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ];

            if ($config->get('persistent')) {
                $options[PDO::ATTR_PERSISTENT] = true;
            }

            // if ($timeout = $config->get('timeout', 0, ['pint'])) {
            // $options[PDO::ATTR_TIMEOUT] = $timeout;
            // }

            $pdo = new PDO($dsn, $user, $pswd, $options);

            // $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            return $pdo;
        } catch (Throwable $th) {
            throw new ConnectionExceptor('CONNECTION_TO_MYSQL_FAILED', \compact('dsn'), $th);
        }
    }

    public static function redis(iterable $config = [], string $connection = null) : \Redis
    {
        $config = IS::collection($config) ? $config : Format::collect($config);

        $auth = $config->get('auth', false, ['type' => 'bool']);
        $host = $config->get('host', '127.0.0.1', ['type' => 'string']);
        $port = $config->get('port', 6379, ['type' => 'uint']);
        $pswd = $auth ? $config->get('password', null, ['need' => 1, 'type' => 'string']) : null;
        $dbnum = $config->get('database', 15, ['type' => 'uint']);
        $timeout = $config->get('timeout', 3, ['type' => 'int']);

        try {
            $redis = new \Redis;
        } catch (Throwable $th) {
            throw new ConnectionExceptor('REDIS_INIT_ERROR', $th);
        }

        try {
            $redis->connect($host, $port, $timeout);
            if (IS::confirm($auth)) {
                $redis->auth($pswd);
            }
            if ($dbnum) {
                $redis->select($dbnum);
            }

            return $redis;
        } catch (Throwable $th) {
            throw new ConnectionExceptor('CONNECTION_TO_REDIS_FAILED', \compact('host', 'port'), $th);
        }
    }

    public static function memcached(iterable $config = [], string $connection = null) : \Memcached
    {
        try {
            $memcached = new \Memcached($connection);
        } catch (Throwable $th) {
            throw new ConnectionExceptor('MEMCACHED_INIT_ERROR', $th);
        }

        $config = IS::collection($config) ? $config : Format::collect($config);

        if ($config->tcp_nodelay ?? true) {
            $memcached->setOption(\Memcached::OPT_TCP_NODELAY, true);
        }
        if (! ($config->compression ?? false)) {
            $memcached->setOption(\Memcached::OPT_COMPRESSION, false);
        }
        if ($config->binary_protocol ?? false) {
            $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
        }
        if ($config->libketama_compatible ?? true) {
            $memcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
        }

        $host = $config->get('host', '127.0.0.1', ['type' => 'string']);
        $port = $config->get('port', 11211, ['type' => 'pint']);
        $weight = $config->get('weight', 0, ['type' => 'int']);

        $memcached->addServer($host, $port, $weight);

        if (IS::confirm($config->get('sasl_auth', false, ['type' => 'bool']))) {
            $user = $config->get('sasl_user', '', ['type' => 'string']);
            $pswd = $config->get('sasl_pswd', '', ['type' => 'string']);

            $memcached->setSaslAuthData($user, $pswd);
        }

        if (false === $memcached->getStats()) {
            throw new ConnectionExceptor('MEMCACHED_CONNECTION_LOST', \compact('host', 'port'));
        }

        return $memcached;
    }
}
