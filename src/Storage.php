<?php

declare(strict_types=1);

namespace DOF\Storage;

use Closure;

abstract class Storage implements Storable
{
    use \DOF\Storage\Traits\LogableStorage;

    abstract public function connectable(float &$delay = null) : bool;
}
