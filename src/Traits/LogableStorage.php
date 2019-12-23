<?php

declare(strict_types=1);

namespace DOF\Storage\Traits;

use Closure;
use DOF\Traits\Tracker;
use DOF\Util\Format;
use DOF\Util\TypeCast;
use DOF\Storage\Driver;
use DOF\Storage\Exceptor\StorageExceptor;

trait LogableStorage
{
    use Tracker;

    /** @var array: Options for current storage driver */
    protected $options = [];

    /**
     * @var object|mixed: Connection to current storage driver
     * @Annotation(0)
     */
    protected $connection;

    /**
     * @var Closure: The getter to get acutal connection
     * @Annotation(0)
     */
    protected $connector;

    /** @var array: SQLs or commands been executed in this instance lifetime */
    protected $log = [];

    /** @var array: Statuses of current storage instance */
    protected $status = [
        'logging'  => true,    // Logging querys or not
        'database' => null,    // Latest database name been used
    ];

    final public function __construct(array $options = [], bool $logging = true)
    {
        $this->options = $options;
        $this->status['logging'] = $logging;
    }

    final public function connector(Closure $connector)
    {
        $this->connector = $connector;

        return $this;
    }

    final public function connected()
    {
        if ($this->status['logging'] ?? false) {
            $this->register('before-shutdown', static::class, function () {
                try {
                    $this->context(Driver::storage($this), $this->log, static::class);
                } catch (Throwable $th) {
                    $this->logger()->exception('GetStorageLoggingContextException', Format::throwable($th));
                }
            });
        }
        if (\method_exists($this, 'cleanup')) {
            $this->register('shutdown', static::class, function () {
                try {
                    $this->cleanup();
                } catch (Throwable $th) {
                    $this->logger()->exception('CleanUpStorageException', Format::throwable($th));
                }
            });
        }
    }

    public function connection(bool $needdb = true)
    {
        if ((! $this->connection) && $this->connector) {
            $this->connection = ($this->connector)();

            $this->connection && $this->connected();
        }
        if (! $this->connection) {
            throw new StorageExceptor('MISSING_STORAGE_CONNECTION', ['driver' => static::class]);
        }

        if ($needdb) {
            $db = $this->options['meta']['DATABASE'] ?? null;
            if (\is_null($db) || (! \is_scalar($db))) {
                throw new StorageExceptor('MISSING_OR_INVALID_DATABASE_IN_OPTIONS', $this->options['meta'] ?? []);
            }

            $_db = $this->status['database'] ?? null;
            if ((! $_db) || ($db !== $_db)) {
                switch ($driver = Driver::name($this)) {
                    case 'redis':
                        $this->logAfter('select', function () use ($db) {
                            $this->connection->select(TypeCast::uint($db));
                        }, $db);
                        break;
                    case 'mysql':
                        $sql = "USE `{$db}`";
                        $this->logAfter($sql, function () use ($sql) {
                            $this->connection->exec($sql);
                        });
                        break;
                    case 'memcached':
                        // ignore storage drivers no database concept even if $database parameter is specified
                        break;
                    default:
                        throw new StorageExceptor('UNSUPPORTED_DRIVER_FOR_DATABASE_USING', \compact('db', 'driver'));
                        break;
                }

                $this->status['database'] = $db;
            }
        }

        return $this->connection;
    }

    final public function log(string $query, float $start, ...$params)
    {
        if ($this->status['logging'] ?? false) {
            $end = \microtime(true);
            $this->log[] = [Format::microtime('T Y-m-d H:i:s', '.', $start), \trim($query), $params, ($end - $start)];
        }
    }

    final public function logAfter(string $query, Closure $after, ...$params)
    {
        $start = \microtime(true);

        $result = $after();

        $this->log($query, $start, ...$params);

        return $result;
    }

    final public function getLog()
    {
        return $this->log;
    }
}
