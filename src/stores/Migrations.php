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
		$query = $this->query()
			->select($select)
			->from(self::getTable());

		if(isset($options['order']))
		{
			$sql->orderBy($options['order']);
		}
		
		$query = $this->prepareQuery($query);
		$query->execute();
		
		$result = $query->fetchAll(PDO::FETCH_OBJ | PDO::FETCH_GROUP, Migration::class);

		return $result ? $result : []; // case when migrations table is not yet in db and fetch returns false
	}
}
