<?php

declare(strict_types=1);

namespace DOF\Storage;

use DOF\DOF;
use DOF\Util\Str;
use DOF\Util\IS;
use DOF\Util\Reflect;
use DOF\Storage\Exceptor\MySQLSchemaExceptor;

class MySQLSchema
{
    use \DOF\Traits\DI;

    const DEFAULT_ENGINE = 'InnoDB';
    const DEFAULT_CHARSET = 'utf8mb4';
    const TYPE_ALIAS = [
        'STRING' => 'VARCHAR',
        'PINT' => 'INT',
        'UINT' => 'INT',
        'BINT' => 'TINYINT',
    ];

    private $storage;
    private $annotations = [];
    private $driver;
    private $force = false;
    private $dump = false;
    private $sqls = [];

    public function reset()
    {
        $this->storage = null;
        $this->annotations = [];
        $this->driver = null;
        $this->force = false;
        $this->dump = false;
        $this->sqls = [];

        return $this;
    }

    public function prepare()
    {
        $meta = $this->annotations['meta'] ?? [];
        $database = $meta['DATABASE'] ?? null;
        if (! $database) {
            throw new MySQLSchemaExceptor('STORAGE_DATABASE_NOT_SET', [$this->storage]);
        }
        $table = $meta['TABLE'] ?? null;
        if (! $table) {
            throw new MySQLSchemaExceptor('STORAGE_TABLE_NAME_NOT_SET', [$this->storage]);
        }
        $prefix = $meta['PREFIX'] ?? null;
        if ($prefix) {
            $table = "{$prefix}{$table}";
        }

        return [$database, $table];
    }

    public function init()
    {
        list($database, $table) = $this->prepare();

        if (! $this->existsDatabase($database)) {
            $this->initDatabase($database);
        }

        $this->initTable($database, $table);

        if ($this->dump) {
            $file = DOF::pathof(Reflect::getNamespaceFile($this->storage));
            \array_unshift($this->sqls, "-- {$this->storage} | {$file}");

            return $this->sqls;
        }

        return true;
    }

    public function sync()
    {
        list($database, $table) = $this->prepare();

        if ($this->existsDatabase($database)) {
            if ($this->existsTable($database, $table)) {
                $this->syncTable($database, $table);
            } else {
                $this->initTable($database, $table);
            }
        } else {
            $this->initDatabase($database);
            $this->initTable($database, $table);
        }

        if ($this->dump) {
            $file = DOF::pathof(Reflect::getNamespaceFile($this->storage));
            \array_unshift($this->sqls, "-- {$this->storage} | {$file}");

            return $this->sqls;
        }

        return true;
    }

    private function syncTableColumns(string $db, string $table)
    {
        $meta = $this->annotations['meta'] ?? [];
        if (IS::confirm($meta['NOSYNC'] ?? 0)) {
            return;
        }

        $columns = $this->annotations['columns'] ?? [];
        $properties = $this->annotations['properties'] ?? [];

        $_columns = $this->mysql()->rawGet("SHOW FULL COLUMNS FROM `{$table}` FROM `{$db}`");
        $_columnNames = \array_column($_columns, 'Field');
        $_columns = \array_combine($_columnNames, $_columns);
        // sort($_columnNames);
        $columnNames = \array_keys($columns);
        // sort($columnNames);

        $columnsAdd = \array_diff($columnNames, $_columnNames);
        if ($columnsAdd) {
            $add = "ALTER TABLE `{$db}`.`{$table}` ";
            foreach ($columnsAdd as $column) {
                $property = $columns[$column] ?? null;
                $property = $properties[$property] ?? null;
                if (! $property) {
                    throw new MySQLSchemaExceptor('COLUMN_WITHOUT_PROPERTY', \compact('table', 'column'));
                }

                $_type = $type = $this->typealias($property['TYPE'] ?? null);
                if (! $type) {
                    throw new MySQLSchemaExceptor('COLUMN_WITHOUT_TYPE', \compact('column'));
                }
                $length = $property['LENGTH'] ?? null;
                if (! IS::ciin($type, ['text', 'blob'])) {
                    if (\is_null($length)) {
                        throw new MySQLSchemaExceptor('COLUMN_WITHOUT_LENGTH', \compact('column', 'type'));
                    }

                    $_type .= ($decimal = ($property['DECIMAL'] ?? null)) ? "({$length}, {$decimal})" : "({$length})";
                }

                $notnull = 'NOT NULL';
                if (($property['NOTNULL'] ?? null) == '0') {
                    $notnull = '';
                }
                $default = '';
                if (\array_key_exists('DEFAULT', $property)) {
                    $_default = $property['DEFAULT'] ?? null;
                    $default = "DEFAULT '{$_default}'";
                }
                $comment = '';
                if ($_comment = (\trim(\strval($property['COMMENT'] ?? '')) ?: \trim(\strval($property['TITLE'] ?? '')))) {
                    $comment = 'COMMENT '.$this->mysql()->quote($_comment);
                }
                $autoinc = '';
                if (\trim(\strval($property['AUTOINC'] ?? '')) === '1') {
                    $autoinc = 'AUTO_INCREMENT';
                }
                $unsigned = (($property['UNSIGNED'] ?? null) == 1) ? 'UNSIGNED' : '';

                $add .= "ADD COLUMN `{$column}` {$_type} {$unsigned} {$notnull} {$autoinc} {$default} {$comment}";
                if (false !== \next($columnsAdd)) {
                    $add .= ",\n";
                }
            }

            $add .= ';';

            if ($this->dump) {
                $this->sqls[] = $add;
            } else {
                $this->mysql()->rawExec($add);
            }
        }

        $columnsUpdate = \array_intersect($columnNames, $_columnNames);
        foreach ($columnsUpdate as $column) {
            $attrs = $properties[$columns[$column] ?? null] ?? [];
            if (! $attrs) {
                throw new MySQLSchemaExceptor('COLUMN_WITHOUT_ATTRS', \compact('table', 'column'));
            }

            $typeInCode = \trim(\strval($attrs['TYPE'] ?? null));
            $lengthInCode = \trim(\strval($attrs['LENGTH'] ?? null));
            if (! $typeInCode) {
                throw new MySQLSchemaExceptor('COLUMN_WITHOUT_TYPE', \compact('table', 'column'));
            }
            $unsignedInCode = (($attrs['UNSIGNED'] ?? null) == 1) ? 'unsigned' : '';
            if (IS::ciin($typeInCode, ['text', 'blob'])) {
                $typeInCode = \trim("{$typeInCode} {$unsignedInCode}");
            } else {
                if (! $lengthInCode) {
                    $type = $typeInCode;
                    throw new MySQLSchemaExceptor('COLUMN_WITHOUT_LENGTH', \compact('table', 'column', 'type'));
                }

                $lengthInCode = ($decimal = ($attrs['DECIMAL'] ?? null)) ? "{$lengthInCode}, {$decimal}" : "{$lengthInCode}";
                $typeInCode = \trim("{$typeInCode}({$lengthInCode}) {$unsignedInCode}");
            }

            $notnullInCode = Str::eq($attrs['NOTNULL'] ?? '1', '1', true);
            $defaultInCode = \array_key_exists('DEFAULTNULL', $attrs) ? null : ($attrs['DEFAULT'] ?? null);
            $commentInCode = \trim(\strval($attrs['COMMENT'] ?? '')) ?: \trim(\strval($attrs['TITLE'] ?? ''));
            $autoincInCode = Str::eq(\trim(\strval($attrs['AUTOINC'] ?? '')), '1', true);

            $_column = $_columns[$column] ?? null;
            if (! $_column) {
                throw new MySQLSchemaExceptor('UNDEFINED_COLUMN_IN_SCHEMA', \compact('db', 'table', 'column'));
            }
            $typeInSchema = \trim(\strval($_column['Type'] ?? ''));
            $notnullInSchema = Str::eq($_column['Null'] ?? 'NO', 'no', true);
            $defaultInSchema = $_column['Default'] ?? null;
            $commentInSchema = \trim(\strval($_column['Comment'] ?? ''));
            $autoincInSchema = Str::eq(\trim(\strval($_column['Extra'] ?? '')), 'auto_increment', true);

            if (false
                || (! Str::eq($typeInCode, $typeInSchema, true))
                || ($notnullInCode !== $notnullInSchema)
                || ($defaultInCode !== $defaultInSchema)
                || (! Str::eq($commentInCode, $commentInSchema, true))
                || ($autoincInCode !== $autoincInSchema)
            ) {
                // update table column with schema in annotations
                $notnull = $notnullInCode ? 'NOT NULL' : '';
                $default = '';
                if (\array_key_exists('DEFAULTNULL', $attrs)) {
                    $default = 'DEFAULT NULL';
                } elseif (\array_key_exists('DEFAULT', $attrs)) {
                    $default = 'DEFAULT '.$this->mysql()->quote($attrs['DEFAULT'] ?? '');
                }
                $comment = '';
                if ($commentInCode) {
                    $comment = 'COMMENT '.$this->mysql()->quote($commentInCode);
                }

                $autoinc = '';
                if (false
                    || $autoincInCode
                    || Str::eq(\strval($meta['PRIMARYKEY'] ?? 'id'), $column, true)
                ) {
                    $autoinc = 'AUTO_INCREMENT';
                }

                // Update null value use default if NOT NULL is set to avoid (1138, Invalid use of NULL value) mysql error
                if ($notnull) {
                    $updateNull = "UPDATE `{$db}`.`{$table}` SET `{$column}` = {$default} WHERE `{$column}` IS NULL";
                }

                $modify = "ALTER TABLE `{$db}`.`{$table}` MODIFY `{$column}` {$typeInCode} {$notnull} {$autoinc} {$default} {$comment};";
                if ($this->dump) {
                    $this->sqls[] = $modify;
                } else {
                    $this->mysql()->rawExec($modify);
                }
            }
        }

        $columnsDrop = \array_diff($_columnNames, $columnNames);
        if ($columnsDrop && $this->force) {
            $drop = "ALTER TABLE `{$table}` \n";
            $dropColumns = $drop.\join(",\n", \array_map(function ($column) {
                return "DROP `{$column}`";
            }, $columnsDrop));
            $dropColumns .= ';';

            if ($this->dump) {
                $this->sqls[] = $dropColumns;
            } else {
                $this->mysql()->rawExec($dropColumns);
            }

            // !!! MySQL will drop indexes automatically when the columns of that index is dropped
            // !!! So here we MUST NOT drop then again
            // $indexes = $this->mysql()->rawGet("SHOW INDEX FROM `{$table}` WHERE `Column_name` IN({$columnsToDrop})");
            // if ($indexes) {
                // $indexesDrop = \array_unique(\array_column($indexes, 'Key_name'));
                // $dropIndexes = $drop.\join(', ', \array_map(function ($index) {
                    // return "DROP INDEX `{$index}`";
                // }, $indexesDrop));
                // $this->mysql()->rawExec($dropIndexes);
            // }
        }
    }

    private function syncTableIndexes(string $db, string $table)
    {
        $meta = $this->annotations['meta'] ?? [];
        if (IS::confirm($meta['NOSYNC'] ?? 0)) {
            return;
        }

        $columns = $this->annotations['columns'] ?? [];
        $properties = $this->annotations['properties'] ?? [];

        $_indexes = $this->mysql()->rawGet("SHOW INDEX FROM `{$table}` FROM `{$db}` WHERE `KEY_NAME` != 'PRIMARY'");
        $_indexNames = \array_unique(\array_column($_indexes, 'Key_name'));
        $indexes = $meta['INDEX'] ?? [];
        $uniques = $meta['UNIQUE'] ?? [];
        $indexNames = \array_keys(\array_merge($indexes, $uniques));

        $indexesAdd = \array_diff($indexNames, $_indexNames);
        if ($indexesAdd) {
            $addIndexes = "ALTER TABLE `{$table}` ";
            foreach ($indexesAdd as $key) {
                $unique = '';
                $fields = $indexes[$key] ?? [];
                if ($_fields = ($uniques[$key] ?? null)) {
                    $unique = 'UNIQUE';
                    $fields = $_fields;
                }
                if (! $fields) {
                    throw new MySQLSchemaExceptor('MISSING_COLUMNS_OF_INDEXKEY', \compact('key', 'unique'));
                }
                foreach ($fields as $field) {
                    if (! ($columns[$field] ?? false)) {
                        throw new MySQLSchemaExceptor('FIELD_OF_INDEX_KEY_NOT_EXISTS', \compact('field', 'key'));
                    }
                }

                $fields = \join(',', \array_map(function ($field) {
                    return "`{$field}`";
                }, $fields));

                $addIndexes .= "ADD {$unique} KEY `{$key}`($fields)";
                if (false !== \next($indexesAdd)) {
                    $addIndexes .= ",\n";
                }
            }
            $addIndexes .= ';';

            if ($this->dump) {
                $this->sqls[] = $addIndexes;
            } else {
                $this->mysql()->rawExec($addIndexes);
            }
        }

        $indexesUpdate = \array_intersect($indexNames, $_indexNames);
        foreach ($indexesUpdate as $index) {
            $fieldsOfIndex = $this->mysql()->rawGet("SHOW INDEX FROM `{$table}` FROM `{$db}` WHERE `KEY_NAME` = '{$index}'");
            $_fieldsOfIndex = \array_column($fieldsOfIndex, 'Column_name');
            $columnsOfIndex = $indexes[$index] ?? ($uniques[$index] ?? []);

            // Check index unicity between annotations and db schema
            // Check indexes fields count between annotations and db schema
            $uniqueInCode = \boolval($uniques[$index] ?? false);
            $uniqueInSchema = !\boolval($fieldsOfIndex[0]['Non_unique'] ?? false);
            $unique = $uniqueInCode ? 'UNIQUE' : '';
            $fields = \join(',', \array_map(function ($field) {
                return "`{$field}`";
            }, $columnsOfIndex));

            if (($uniqueInCode !== $uniqueInSchema) || ($columnsOfIndex !== $_fieldsOfIndex)) {
                // re-create index and name as $index with unicity
                $createIndex = "ALTER TABLE `{$db}`.`{$table}` DROP INDEX `{$index}`, ADD {$unique} KEY `{$index}` ({$fields});";
                if ($this->dump) {
                    $this->sqls[] = $createIndex;
                } else {
                    $this->mysql()->rawExec($createIndex);
                }
                continue;
            }
        }

        $indexesDrop = \array_diff($_indexNames, $indexNames);
        if ($indexesDrop && $this->force) {
            $dropIndexes = "ALTER TABLE `{$table}` ";
            $dropIndexes .= \join(', ', \array_map(function ($index) {
                return "DROP KEY `{$index}`";
            }, $indexesDrop));
            $dropIndexes .= ';';

            if ($this->dump) {
                $this->sqls[] = $dropIndexes;
            } else {
                $this->mysql()->rawExec($dropIndexes);
            }
        }
    }

    public function typealias(string $type = null)
    {
        if (! $type) {
            return;
        }

        $type = \strtoupper($type);
        if ($alias = (self::TYPE_ALIAS[$type] ?? null)) {
            return $alias;
        }

        return $type;
    }

    public function syncTableSchema(string $db, string $table)
    {
        if (IS::confirm($this->annotations['meta']['NOSYNC'] ?? 0)) {
            return;
        }

        $_table = $this->mysql()->rawGet("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE table_schema='{$db}' AND table_name='{$table}'")[0] ?? [];
        $commentInSchema = \strval($_table['TABLE_COMMENT'] ?? '');
        $commentInCode = \trim(\strval($this->annotations['meta']['COMMENT'] ?? ''));
        if (($commentInCode !== $commentInSchema) && $commentInCode) {
            $comment = $this->mysql()->quote($commentInCode);
            $updateComment = "ALTER TABLE `{$db}`.`{$table}` COMMENT = {$comment};";
            if ($this->dump) {
                $this->sqls[] = $updateComment;
            } else {
                $this->mysql()->rawExec($updateComment);
            }
        }

        $engineInSchema = \strval($_table['ENGINE'] ?? 'InnoDB');
        $engineInCode = \trim(\strval($this->annotations['meta']['ENGINE'] ?? 'InnoDB'));
        if ((! Str::eq($engineInCode, $engineInSchema, true)) && $engineInCode) {
            $updateEngine = "ALTER TABLE `{$db}`.`{$table}` ENGINE {$engineInCode};";
            if ($this->dump) {
                $this->sqls[] = $updateEngine;
            } else {
                $this->mysql()->rawExec($updateEngine);
            }
        }

        $charsetInCode = \trim(\strval($this->annotations['meta']['CHARSET'] ?? ''));
        if ($charsetInCode) {
            $collateInCode = \trim(\strval($this->annotations['meta']['COLLATE'] ?? ''));
            $collateInSchema = \strval($_table['TABLE_COLLATION'] ?? '');
            if (Str::eq($collateInCode, $collateInSchema, true)) {
                $charsetInSchema = '';
                $__table = $this->mysql()->rawGet("SHOW CREATE TABLE `{$db}`.`{$table}`");
                $tmp = \explode(PHP_EOL, \array_values($__table[0] ?? [])[1] ?? '');
                $tmp = $tmp[\count($tmp) - 1] ?? '';
                if ($tmp) {
                    $reg = '#CHARSET\=((\w)+)#';
                    $res = [];
                    \preg_match($reg, $tmp, $res);
                    $charsetInSchema = $res[1] ?? '';
                }

                if (! Str::eq($charsetInSchema, $charsetInCode, true)) {
                    $updateCharset = "ALTER TABLE `{$db}`.`{$table}` CONVERT TO CHARACTER SET {$charsetInCode};";
                    if ($this->dump) {
                        $this->sqls[] = $updateCharset;
                    } else {
                        $this->mysql()->rawExec($updateCharset);
                    }
                }
            } elseif ($collateInCode) {
                $updateCollate = "ALTER TABLE `{$db}`.`{$table}` CONVERT TO CHARACTER SET {$charsetInCode} COLLATE {$collateInCode};";
                if ($this->dump) {
                    $this->sqls[] = $updateCollate;
                } else {
                    $this->mysql()->rawExec($updateCollate);
                }
            }
        }
    }

    /**
     * Compare columns from table schema to storage annotations
     * Then decide whether add/drop/modification operations on columns and indexes are required
     */
    public function syncTable(string $db, string $table)
    {
        self::syncTableSchema($db, $table);
        self::syncTableColumns($db, $table);
        self::syncTableIndexes($db, $table);
    }

    public function initDatabase(string $name)
    {
        $dropDB = "DROP DATABASE IF EXISTS `{$name}`;";
        $createDB = "CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4;";

        if ($this->dump) {
            if ($this->force) {
                $this->sqls[] = $dropDB;
            }
            $this->sqls[] = $createDB;
        } else {
            if ($this->force) {
                $this->mysql()->rawExec($dropDB);
            }

            $this->mysql()->rawExec($createDB);
        }
    }

    public function initTable(string $db, string $table)
    {
        $meta = $this->annotations['meta'] ?? [];
        if (IS::confirm($meta['NOSYNC'] ?? 0)) {
            return;
        }

        $columns = $this->annotations['columns'] ?? [];
        $properties = $this->annotations['properties'] ?? [];

        $engine = $meta['ENGINE'] ?? self::DEFAULT_ENGINE;
        $charset = $meta['CHARSET'] ?? self::DEFAULT_CHARSET;
        $notes = $this->mysql()->quote($meta['COMMENT'] ?? '');
        $pkName = $meta['PRIMARYKEY'] ?? 'id';
        $pkType = $meta['PRIMARYTYPE'] ?? 'int';
        $pkLength = $meta['PRIMARYLEN'] ?? 10;
        $indexes = '';
        $uniques = '';
        $fields = '';

        foreach ($meta['INDEX'] ?? [] as $index => $_fields) {
            \array_walk($_fields, function (&$field) {
                $field = "`{$field}`";
            });
            $_fields = \join(',', $_fields);
            $indexes .= "KEY `{$index}` ($_fields), \n";
        }

        foreach ($meta['UNIQUE'] ?? [] as $index => $_fields) {
            \array_walk($_fields, function (&$field) {
                $field = "`{$field}`";
            });
            $_fields = \join(',', $_fields);
            $uniques .= "UNIQUE KEY `{$index}` ($_fields), ";
        }

        foreach ($columns as $column => $property) {
            $attr = $properties[$property] ?? null;
            if (! $attr) {
                continue;
            }
            $_type = $type = $this->typealias($attr['TYPE'] ?? null);
            if (! $type) {
                throw new MySQLSchemaExceptor('COLUMN_WITHOUT_TYPE', \compact('column'));
            }
            $len = $attr['LENGTH'] ?? null;
            if (! IS::ciin($type, ['text', 'blob'])) {
                if (\is_null($len)) {
                    throw new MySQLSchemaExceptor('COLUMN_WITHOUT_LENGTH', \compact('column', 'type'));
                }

                $_type .= ($decimal = ($attr['DECIMAL'] ?? null)) ? "({$len}, {$decimal})" : "({$len})";
            }

            if ($column === $pkName) {
                $pkType = $type;
                $pkLength = $len;
                continue;
            }

            $unsigned = (($attr['UNSIGNED'] ?? null) == 1) ? 'UNSIGNED' : '';
            $nullable = 'NOT NULL';
            if (($attr['NOTNULL'] ?? null) == '0') {
                $nullable = '';
            }
            $default = '';
            if (\array_key_exists('DEFAULT', $attr)) {
                $_default = $attr['DEFAULT'] ?? null;
                $default = "DEFAULT '{$_default}'";
            }
            $comment = '';
            if ($_comment = (\trim($attr['COMMENT'] ?? '') ?: \trim($attr['TITLE'] ?? ''))) {
                $comment = 'COMMENT '.$this->mysql()->quote($_comment);
            }

            $fields .= "`{$column}` {$_type} {$unsigned} {$nullable} {$default} {$comment}, \n";
        }

        $useDb = "USE `{$db}`;";
        $dropTable = "DROP TABLE IF EXISTS `{$db}`.`{$table}`;";

        $createTable = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
`{$pkName}` {$pkType}({$pkLength}) UNSIGNED NOT NULL AUTO_INCREMENT,
{$fields}
{$indexes}
{$uniques}
PRIMARY KEY (`{$pkName}`)
) ENGINE={$engine} AUTO_INCREMENT=1 DEFAULT CHARSET={$charset} COMMENT={$notes};
SQL;

        $onTableCreated = \method_exists($this->storage, 'onTableCreated');

        if ($this->dump) {
            $this->sqls[] = $useDb;
            if ($this->force) {
                $this->sqls[] = $dropTable;
            }
            $this->sqls[] = $createTable;
            if ($onTableCreated) {
                $this->sqls[] = "-- FOUND CALLBACK ON TABLE CREATED: {$this->storage}@onTableCreated()";
            }
        } else {
            $this->mysql()->rawExec($useDb);
            if ($this->force) {
                $this->mysql()->rawExec($dropTable);
            }
            $this->mysql()->rawExec($createTable);

            if ($onTableCreated) {
                $this->di($this->storage)->onTableCreated();
            }
        }
    }

    public function existsTable(string $db, string $table) : bool
    {
        $useDb = "USE `{$db}`;";

        $this->mysql()->rawExec($useDb);

        $this->sqls[] = $useDb;

        $res = $this->mysql()->rawGet("SHOW TABLES LIKE '{$table}'");

        return \count($res[0] ?? []) > 0;
    }

    public function existsDatabase(string $name) : bool
    {
        $res = $this->mysql()->rawGet("SHOW DATABASES LIKE '{$name}'");

        return \count($res[0] ?? []) > 0;
    }

    final public function mysql() : MySQL
    {
        if ((! $this->driver) || (! ($this->driver instanceof MySQL))) {
            throw new MySQLSchemaExceptor('MISSING_OR_INVALID_MYSQL_DRIVER', ['driver' => $this->driver]);
        }

        return $this->driver;
    }

    /**
     * Setter for storage
     *
     * @param string $storage
     * @return MySQLSchema
     */
    public function setStorage(string $storage)
    {
        $this->storage = $storage;
    
        return $this;
    }

    /**
     * Setter for annotations
     *
     * @param array $annotations
     * @return MySQLSchema
     */
    public function setAnnotations(array $annotations)
    {
        $this->annotations = $annotations;
    
        return $this;
    }

    /**
     * Setter for driver
     *
     * @param MySQL $driver
     * @return MySQLSchema
     */
    public function setDriver(MySQL $driver)
    {
        $this->driver = $driver;
    
        return $this;
    }

    /**
     * Setter for force
     *
     * @param bool $force
     * @return MySQLSchema
     */
    public function setForce(bool $force)
    {
        $this->force = $force;
    
        return $this;
    }

    /**
     * Setter for dump
     *
     * @param bool $dump
     * @return MySQLSchema
     */
    public function setDump(bool $dump)
    {
        $this->dump = $dump;
    
        return $this;
    }
}
