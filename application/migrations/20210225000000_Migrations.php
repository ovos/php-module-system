<?php
declare(strict_types=1);

namespace Migrations;

use Ovos\Migration;

/**
 * Migrations
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Migrations extends Migration
{
	public function up(): void
	{
		$this->upSql();
	}
	
	public function down(): void
	{
		$this->downSql();
	}
}
