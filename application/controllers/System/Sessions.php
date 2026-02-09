<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Functions;
use Ovos\Response\Json;
use Ovos\Service\Session;

/**
 * Sessions
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Sessions extends Controller\Cli
{
	use Controller\Traits\Cli;
	
	/**
	 * Allows accessing specified CLI methods via HTTP
	 */
	protected array $httpActions = [
		'keep-alive',
	];
	
	/**
	 * Clears sessions
	 */
	public function clear(): void
	{
		Functions::println('Clearing sessions ...' . PHP_EOL);
		
		$session = $this->container
			->getClass(Session::class);
		if($session->flush())
		{
			Functions::println('<green>Sessions cleared.<reset>', true);
		}
		else
		{
			Functions::println('<red>Error clearing sessions.<reset>', true);
		}
	}
	
	/**
	 * Keep-alive
	 */
	public function keepAlive(): Json
	{
		return new Json;
	}
}
