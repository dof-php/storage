<?php

use DOF\Util\Str;
use DOF\Storage\MySQLBuilder;

$gwt->true('Test SQL for MySQLBuilder::zero()', Str::eq((new MySQLBuilder)->zero('is_disabled', 'is_deleted')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `is_disabled` = 0 OR `is_deleted` = 0", false, true));
$gwt->true('Test SQL for MySQLBuilder::zeros()', Str::eq((new MySQLBuilder)->zeros('is_disabled', 'is_deleted')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `is_disabled` = 0 AND `is_deleted` = 0", false, true));
$gwt->true('Test SQL for MySQLBuilder::empty()', Str::eq((new MySQLBuilder)->empty('name', 'address')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` = '' OR `address` = ''", false, true));
$gwt->true('Test SQL for MySQLBuilder::emptys()', Str::eq((new MySQLBuilder)->emptys('name', 'address')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` = '' AND `address` = ''", false, true));
$gwt->true('Test SQL for MySQLBuilder::null()', Str::eq((new MySQLBuilder)->null('name', 'address')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` IS NULL  OR `address` IS NULL", false, true));
$gwt->true('Test SQL for MySQLBuilder::nulls()', Str::eq((new MySQLBuilder)->nulls('name', 'address')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` IS NULL  AND `address` IS NULL", false, true));
$gwt->true('Test SQL for MySQLBuilder::notnull()', Str::eq((new MySQLBuilder)->notnull('name', 'address')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` IS NOT NULL  OR `address` IS NOT NULL", false, true));
$gwt->true('Test SQL for MySQLBuilder::notnulls()', Str::eq((new MySQLBuilder)->notnulls('name', 'address')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` IS NOT NULL  AND `address` IS NOT NULL", false, true));

$gwt->true('Test SQL for MySQLBuilder::not()', Str::eq((new MySQLBuilder)->not('name', 'dof')->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` != 'dof'", false, true));
$gwt->true('Test SQL for MySQLBuilder::not() - numbers without single quotation marks', Str::eq((new MySQLBuilder)->not('name', 1)->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` != 1", false, true));
$gwt->true('Test SQL for MySQLBuilder::not() - not null', Str::eq((new MySQLBuilder)->not('name', null)->sql(true)->get(), "SELECT * FROM #{TABLE}  WHERE `name` IS NOT NULL", false, true));
