<?php

namespace Stores;

use Ovos\Store\Mysql;
use Models\Migration;
use PDO;
use PDOException;

/**
 * Migrations
 *
 * @package Stores
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
	 * @return false|Migration[]
	 */
	public function getAll(
		$select = 'id, ' . self::TABLE . '.*',
		$options = []
	): false|array // group by ID
	{
		$query = $this->query()
			->select($select)
			->from(self::TABLE);

		if(isset($options['order']))
		{
			$query->orderBy(...$options['order']);
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
