<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Plugins\Layout;
use Ovos\Response;
use Ovos\Size;

use function disk_free_space;

/**
 * Stats
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Stats extends Controller\Cli
{
	protected array $httpActions = [
		'free-space',
	];
	
	/**
	 * Returns amount of free space
	 */
	public function freeSpace(
		bool $formatSize = true,
		bool $eol = true,
	): Response
	{
		if($this->hasPlugin(Layout::SYMBOL))
		{
			$this->removePlugin(Layout::SYMBOL);
		}
		
		$response = new Response\Html;
		
		$freeSpace = disk_free_space('.');
		$response->set(
			(string)($formatSize ? Size::format((int)$freeSpace) : $freeSpace)
		);
		if($eol)
		{
			$response->append(PHP_EOL);
		}
		
		return $response;
	}
}