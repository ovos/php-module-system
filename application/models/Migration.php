<?php

namespace Models;

use Ovos\Model\Mysql;
use Ovos\Model\Mysql\Template\Timestamps;
use Override;
use Stores\Migrations;

/**
 * Migration
 *
 * @author Marcin Gil <mg@ovos.at>
 *
 * @property int $id
 * @property string $name
 * @property mixed $created_at
 * @property mixed $modified_at
 * @property mixed $migrated_at
 * @property mixed $rolled_back_at
 */
class Migration extends Mysql
{
	/**
	 * Autoincrement key
	 */
	protected ?string $autoIncrementKey = null;
	
	#[Override]
	public static function getStoreClass(): string
	{
		return Migrations::class;
	}
	
	#[Override]
	public function setUp(): void
	{
		$this->addTemplate(new Timestamps);
	}
}
