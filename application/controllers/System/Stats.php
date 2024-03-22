<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Plugins\Layout;
use Ovos\Response;
use Ovos\Size;

/**
 * Stats
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Stats extends Controller\Cli
{
	/**
	 * @var array
	 */
	protected array $_httpActions = [
		'free-space',
	];

	/**
	 * Returns amount of free space
	 *
	 * @param bool $formatSize
	 * @param bool $eol
	 * 
	 * @return Response
	 */
	public function freeSpace(bool $formatSize = true, bool $eol = true): Response
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