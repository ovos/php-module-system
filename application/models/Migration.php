<?php

namespace Models;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template\Timestamps;
use Stores\Migrations;

/**
 * Migration
 *
 * @package Models
 * @author Marcin Gil <mg@ovos.at>
 *
 * @property int $id
 * @property string $name
 * @property mixed $created_at
 * @property mixed $modified_at
 * @property mixed $migrated_at
 * @property mixed $rolledback_at
 */
class Migration extends Mysql
{
	/**
	 * Autoincrement key
	 *
	 * @var ?string
	 */
	protected ?string $_autoIncrementKey = null;
	
	/**
	 * @return string
	 */
	public static function getStoreClass(): string
	{
		return Migrations::class;
	}
	
	public function setUp(): void
	{
		$this->addTemplate(new Timestamps);
	}
}
