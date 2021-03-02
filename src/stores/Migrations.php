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
	public function getAll($select = 'id, migrations.*', $options = []): array|false
	{
		$query = $this->query()
			->select($select)
			->from(self::getTable());

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
		$result = $query->fetchAll(PDO::FETCH_CLASS | PDO::FETCH_GROUP , Migration::class); // group by ID
		return array_map(fn($row) => reset($row), $result);	
	}
}
