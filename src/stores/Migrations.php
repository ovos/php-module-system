<?php

namespace Stores;

use Ovos\Store\Mysql;
use Models\Migration;
use PDO;
use PDOException;

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
	public function getAll(
		$select = 'id, ' . self::TABLE . '.*',
		$options = []
	): array|false // group by ID
	{
		$query = $this->query()
			->select($select)
			->from(self::TABLE);

		if(isset($options['order']))
		{
			$sql->orderBy($options['order']);
		}
		
		try
		{
			$query = $this->prepareQuery($query);
		}
		catch (PDOException $exception)
		{
			return []; // case when migrations table is not yet in db
		}
		
		$query->execute();
		return $this->fetchGrouped($query, Migration::class); // group by first column
	}
}
