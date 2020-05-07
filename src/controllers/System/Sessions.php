<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Functions;
use Ovos\Service\Session;
use function Ovos\services;

/**
 * Sessions
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Sessions extends Controller\Cli
{
	use Controller\Traits\Cli;

	/**
	 * Clears sessions
	 */
	public function clear(): void
	{
		Functions::println('Clearing sessions ...' . PHP_EOL);
		
		$session = new Session;
		if($session->flush())
		{
			Functions::println('<green>Sessions cleared.<reset>', true);
		}
		else
		{
			Functions::println('<red>Error clearing sessions.<reset>', true);
		}	
	}
}
