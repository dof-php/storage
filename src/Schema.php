<?php

declare(strict_types=1);

namespace DOF\Storage;

use DOF\Storage\MySQLSchema;

class Schema
{
    const LIST = [
        'mysql' => MySQLSchema::class,
    ];

    public static function support(string $driver) : bool
    {
        return \array_key_exists(\strtolower($driver), static::LIST);
    }

    public static function get(string $driver) : ?string
    {
        return static::LIST[\strtolower($driver)] ?? null;
    }
}
