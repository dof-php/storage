<?php

declare(strict_types=1);

namespace DOF\Storage;

use DOF\DOF;
use DOF\Convention;
use DOF\Util\FS;

final class Command
{
    /**
     * @CMD(tpl.mysql)
     * @Desc(Init a storage config template in for MySQL)
     * @Option(force){notes=Force init even if target file exists}
     */
    public function initConfigMySQL($console)
    {
        if (! \is_file($tpl = FS::path(\dirname(__DIR__), Convention::DIR_TEMPLATE, 'mysql'))) {
            $console->error('MySQL config template not found', \compact('tpl'));
        }
        if (\is_file($mysql = DOF::path(Convention::DIR_CONFIG, 'mysql.php'))) {
            if ($console->hasOption('force')) {
                FS::unlink($mysql);
            } else {
                $console->fail('MySQL config file already exists.', \compact('mysql'));
            }
        }

        FS::copy($tpl, $mysql);
        $console->ok(DOF::pathof($mysql));
    }

    /**
     * @CMD(tpl.redis)
     * @Desc(Init a storage config template in for Redis)
     * @Option(force){notes=Force init even if target file exists}
     */
    public function initConfigRedis($console)
    {
        if (! \is_file($tpl = FS::path(\dirname(__DIR__), Convention::DIR_TEMPLATE, 'redis'))) {
            $console->error('Redis config template not found', \compact('tpl'));
        }
        if (\is_file($redis = DOF::path(Convention::DIR_CONFIG, 'redis.php'))) {
            if ($console->hasOption('force')) {
                FS::unlink($redis);
            } else {
                $console->fail('Redis config file already exists.', \compact('redis'));
            }
        }

        FS::copy($tpl, $redis);
        $console->ok(DOF::pathof($redis));
    }

    /**
     * @CMD(tpl.memcached)
     * @Desc(Init a storage config template in for Memcached)
     */
    public function initConfigMemcached($console)
    {
        if (! \is_file($tpl = FS::path(\dirname(__DIR__), Convention::DIR_TEMPLATE, 'memcached'))) {
            $console->error('Memcached config template not found', \compact('tpl'));
        }
        if (\is_file($memcached = DOF::path(Convention::DIR_CONFIG, 'memcached.php'))) {
            if ($console->hasOption('force')) {
                FS::unlink($memcached);
            } else {
                $console->fail('Memcached config file already exists.', \compact('memcached'));
            }
        }

        FS::copy($tpl, $memcached);
        $console->ok(DOF::pathof($memcached));
    }
}
