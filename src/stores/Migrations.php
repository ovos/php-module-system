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
	
	/**
	 * @param string $select
	 * @param array $options
	 *
	 * @return Migration[]|false
	 */
	public function getAll($select = '*', $options = []): array|false
	{
		$sql = $this->query()
			->select($select)
			->from(self::TABLE);

		if(isset($options['order']))
		{
			$sql->orderBy($options['order']);
		}
		
		$query = $this->source()->prepare($sql->getSQL());
		$query->execute();

		return $query->fetchObject(Migration::class);
	}
}
