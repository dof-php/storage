<?php

declare(strict_types=1);

namespace DOF\Storage;

use Closure;

interface Storable
{
    public function connector(Closure $connector);

    public function connection();

    public function connectable(float &$delay = null) : bool;
}
