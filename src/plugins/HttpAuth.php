<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
use Ovos\Response;

/**
 * HttpAuth
 *
 * @package Plugins
 * @author Marcin Gil <mg@ovos.at>
 */
class HttpAuth extends Plugin
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'http_auth';
	
	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @return void
	 */
	public function preDispatch(): void
	{
		if(($config = $this->_app->getConfig()->http_auth) === null)
		{
			return;
		}
		
		if($config->enabled === false || empty($config->username))
		{
			return;
		}
		
		if(isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
			&& $_SERVER['PHP_AUTH_USER'] === $config->username 
			&& $_SERVER['PHP_AUTH_PW'] === $config->password
		)
		{
			return;
		}
	
		$response = (new Response\Html)
			->setHeader('WWW-Authenticate', sprintf('Basic realm="%s"', $config->realm))
			->setHttpCode(401);
			
		$this->getController()->setDispatched(true);
	}
}
