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
	 * Garbage-collects session leftovers that do not self-expire: the
	 * json handler's activity index (its documents and locks are
	 * collected by redis TTLs); a no-op under the php handler, whose
	 * native machinery collects its own garbage - safe to run from a cron
	 */
	public function gc(): void
	{
		Functions::println('Collecting session garbage ...' . PHP_EOL);
		
		$session = $this->container
			->getClass(Session::class);
		
		$removed = $session->gc();
		if($removed === null)
		{
			Functions::println('<yellow>Nothing to collect - the "'
				. $session->getHandler()
				. '" handler garbage-collects itself.<reset>', true);
			
			return;
		}
		
		Functions::println('<green>Removed ' . $removed
			. ' stale activity ' . ($removed === 1 ? 'entry' : 'entries')
			. ', ' . (int)$session->countActive()
			. ' active session(s) in the last 5 minutes.<reset>', true);
	}
	
	/**
	 * Keep-alive
	 */
	public function keepAlive(): Json
	{
		return new Json;
	}
}
