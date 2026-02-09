<?php

namespace Stores;

use Ovos\Store\Mysql;
use Models\Migration;
use PDOException;

/**
 * Migrations
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Migrations extends Mysql
{
	/**
	 * Primary table name
	 */
	public const ?string TABLE = 'migrations';
	
	/**
	 * @return Migration[]
	 */
	public function getAll(
		string $select = 'id, ' . self::TABLE . '.*',
		array $options = [],
	): array // group by ID
	{
		$query = $this->query()
			->select($select)
			->from(self::TABLE);
		
		if(isset($options['order']))
		{
			$query->orderBy(...$options['order']);
		}
		if(isset($options['limit']))
		{
			$query->limit($options['limit']);
		}
		
		try
		{
			$query = $this->prepareQuery($query);
		}
		catch(PDOException $exception)
		{
			return []; // case when the migrations table is not yet in db
		}
		
		$query->execute();
		return $this->fetchGrouped($query, Migration::class); // group by first column
	}
}
