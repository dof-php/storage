<?php

$gwt->true('Test igbinary extension loaded', \extension_loaded('igbinary'));
$gwt->true('Test redis extension loaded', \extension_loaded('redis'));
$gwt->true('Test memcached extension loaded', \extension_loaded('memcached'));
