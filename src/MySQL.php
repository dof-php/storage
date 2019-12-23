<?php

declare(strict_types=1);

namespace DOF\Storage;

use PDO;
use Throwable;
use Closure;
use DOF\Util\JSON;
use DOF\Util\TypeHint;
use DOF\Util\Singleton;
use DOF\Storage\MySQLBuilder;
use DOF\Storage\Exceptor\MySQLExceptor;
use DOF\Storage\Exceptor\ViolatedUniqueConstraint;

class MySQL extends Storage
{
    /** @var \DOF\Storage\MySQLBuilder: Query builder based on table */
    private $builder;

    public function builder() : MySQLBuilder
    {
        // return (new MySQLBuilder)->setOrigin($this);
        return Singleton::get(MySQLBuilder::class)->reset()->setOrigin($this);
    }

    /**
     * Add a single record and return primary key
     */
    public function add(array $data) : int
    {
        return $this->builder()->add($data);
    }

    /**
     * Insert a single record and return parimary key
     */
    public function insert(string $sql, array $values) : int
    {
        try {
            \array_walk($values, function (&$val) {
                if (! \is_scalar($val)) {
                    $val = JSON::encode($val);
                }
            });

            $sql = $this->generate($sql);

            $start = \microtime(true);

            $connection = $this->connection();
            $statement = $connection->prepare($sql);
            $statement->execute($values);
            $id = $connection->lastInsertId();

            $this->log($sql, $start, $values);

            // NOTES:
            // - lastInsertId() only work after the INSERT query
            // - In transaction, lastInsertId() should be called before commit()
            return \intval($id);
        } catch (Throwable $th) {
            if (($th->errorInfo[1] ?? null) === 1062) {
                throw new ViolatedUniqueConstraint(\compact('sql', 'values'), $th);
            }

            throw new MySQLExceptor('INSERT_TO_MYSQL_FAILED', \compact('sql', 'values'), $th);
        }
    }

    public function deletes(...$ids) : int
    {
        $ids = \array_unique(\array_filter($ids, function ($id) {
            return TypeHint::pint($id);
        }));

        return $this->builder()->in('id', $ids)->delete();
    }

    /**
     * Delete a record by primary key
     */
    public function delete(int $id) : int
    {
        if ($id < 1) {
            return 0;
        }

        return $this->builder()->where('id', $id)->delete();
    }

    /**
     * Update a single record by primary key
     */
    public function update(int $id, array $data) : int
    {
        if ($id < 1) {
            return 0;
        }

        return $this->builder()->where('id', $id)->update($data);
    }

    /**
     * Find a single record by primary key
     */
    public function find(int $id) : ?array
    {
        if ($id < 1) {
            return null;
        }

        return $this->builder()->where('id', $id)->first();
    }

    public function rawExec(string $sql)
    {
        $start = \microtime(true);

        try {
            $result = $this->connection(false)->exec($sql);
        } catch (Throwable $th) {
            throw new MySQLExceptor('RAW_EXEC_MYSQL_FAILED', \compact('sql'), $th);
        }

        $this->log($sql, $start);

        return $result;
    }

    public function rawGet(string $sql)
    {
        $start = \microtime(true);

        try {
            $statement = $this->connection(false)->query($sql);
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $th) {
            throw new MySQLExceptor('RAW_QUERY_MYSQL_FAILED', \compact('sql'), $th);
        }

        $this->log($sql, $start);

        return $result;
    }

    /**
     * Execute a query with given sql template and parameters
     */
    public function get(string $sql, array $params = null)
    {
        try {
            $sql = $this->generate($sql);

            $start = \microtime(true);

            if (\is_null($params)) {
                $statement = $this->connection()->query($sql);
            } else {
                $statement = $this->connection()->prepare($sql, [
                    PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY,
                ]);

                $idx = 0;
                foreach ($params as $key => $val) {
                    $_key = \is_int($key) ? ++$idx : $key;
                    $statement->bindValue($_key, $val, $this->getPDOValueConst($val));
                }

                $statement->execute();
            }

            $this->log($sql, $start, $params);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $th) {
            throw new MySQLExceptor('QUERY_MYSQL_FAILED', \compact('sql', 'params'), $th);
        }
    }

    public function exec(string $sql, array $params = null)
    {
        try {
            $sql = $this->generate($sql);

            $start = \microtime(true);

            if (\is_null($params)) {
                $result = $this->connection()->exec($sql);
            } else {
                $statement = $this->connection()->prepare($sql);
                $statement->execute($params);

                $result = $statement->rowCount();
            }

            $this->log($sql, $start, $params);

            return $result;
        } catch (Throwable $th) {
            if (($th->errorInfo[1] ?? null) === 1062) {
                throw new ViolatedUniqueConstraint(\compact('sql', 'params'), $th);
            }

            throw new MySQLExceptor('OPERATIONS_TO_MYSQL_FAILED', \compact('sql'), $th);
        }
    }

    public function use(string $dbname)
    {
        $sql = "USE `{$dbname}`";

        $this->exec($sql);

        return $this;
    }

    /**
     * Generate base sql statement from sql template
     *
     * @param string $sql
     * @return string
     */
    public function generate(string $sql) : string
    {
        $sql = \str_replace('#{TABLE}', $this->table(), $sql);
        if ($columns = $this->getSelectColumns()) {
            $sql = \str_replace('#{COLUMNS}', $columns, $sql);
        }

        return $sql;
    }

    public function getSelectColumns(bool $asString = true)
    {
        $columns = \array_keys($this->options['columns'] ?? []);
        if (! $asString) {
            return $columns;
        }

        if (! $columns) {
            return '*';
        }

        return \join(',', \array_map(function ($column) {
            return "`{$column}`";
        }, $columns));
    }

    public function table() : ?string
    {
        $table = $this->options['meta']['TABLE']  ?? null;
        if (! $table) {
            throw new MySQLExceptor('MYSQL_TABLE_NAME_MISSING', $this->options);
        }

        $prefix = $this->options['meta']['PREFIX'] ?? '';
        $db = $this->options['meta']['DATABASE'] ?? null;

        return $db ? "`{$db}`.`{$prefix}{$table}`" : "`{$prefix}{$table}`";
    }

    public function quote($val)
    {
        if (\in_array(\gettype($val), ['integer', 'double', 'float'])) {
            return $val;
        }

        $type = $this->getPDOValueConst($val);

        return $this->logAfter('quote', function () use ($val, $type) {
            return $this->connection(false)->quote(\strval($val), $type);
        });
    }

    public function getPDOValueConst($val)
    {
        switch (\gettype($val)) {
            case 'integer':
                return PDO::PARAM_INT;
            case 'boolean':
                return PDO::PARAM_BOOL;
            case 'NULL':
                return PDO::PARAM_NULL;
            case 'string':
            default:
                return PDO::PARAM_STR;
        }
    }

    public function begin()
    {
        $this->logAfter('BEGIN', function () {
            $this->connection(false)->beginTransaction();
        });
    }

    public function commit()
    {
        $this->logAfter('COMMIT', function () {
            $this->connection(false)->commit();
        });
    }

    public function rollback()
    {
        $this->logAfter('ROLLBACK', function () {
            ($connection = $this->connection(false)) && $connection->inTransaction() && $connection->rollBack();
        });
    }

    public function transaction(Closure $transaction)
    {
        try {
            $this->begin();

            $transaction($this);

            $this->commit();
        } catch (Throwable $th) {
            $this->rollback();
           
            throw new MySQLExceptor('MYSQL_TRANSACTION_FAILED', $th);
        }
    }

    public function connectable(float &$delay = null) : bool
    {
        $start = \microtime(true);
        $connection = $this->connection();
        $delay = \microtime(true) - $start;

        return $connection && (\get_class($connection) === PDO::class);
    }

    public function showSessionId()
    {
        $res = $this->get('SELECT CONNECTION_ID() as session_id');

        return $res[0]['session_id'] ?? '-1';
    }

    public function showTableLocks()
    {
        return $this->get('SHOW OPEN TABLES WHERE IN_USE >= 1');
    }

    // See: <https://www.php.net/manual/en/mysqli.persistconns.php>
    public function cleanup()
    {
        // TODO
        // Rollback uncommited active transactions
        // Close and drop temporary tables
        // Unlock tables of current session
        $this->rawExec('UNLOCK TABLES');
        // Reset session variables
        // Close prepared statements (always happens with PHP)
        // Close handler
        // Release locks acquired with GET_LOCK()
    }
}
