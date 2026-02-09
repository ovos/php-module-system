<?php

namespace Stores;

use Ovos\Store\Mysql;
use Models\Migration;
use PDO;

/**
 * Migrations
 *
 * @package Models
 * @author Marcin Gil <mg@ovos.at>
 */
class Migrations extends Mysql
{
	/**
	 * Primary table name
	 *
	 * @var string
	 */
	public const TABLE = 'migrations';
}
