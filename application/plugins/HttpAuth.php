<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Controller\Plugin;
use Ovos\Response;

use function in_array;

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
	public const string SYMBOL = 'http_auth';
	
	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return self::SYMBOL;
	}
	
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_config;
	
	/**
	 * @param ?ArrayObject $config
	 */
	public function __construct(
		#[Inject('config')]
		#[InjectArrayObject('http_auth')]
		?ArrayObject $config,
	)
	{
		parent::__construct();
		
		$this->_config = $config;
	}
	
	/**
	 * @return void
	 */
	public function preDispatch(): void
	{
		if($this->_config === null)
		{
			return;
		}
		
		// allow clients listed in the whitelist
		if($this->_config->whitelist !== null)
		{
			$clientIp = Client::getIp();
			$whitelist = $this->_config->whitelist->getArrayCopy();
			
			if(in_array($clientIp, $whitelist, true))
			{
				return;
			}
		}
		
		if($this->_config->enabled === false
			|| empty($this->_config->username))
		{
			return;
		}
		
		if(isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
			&& $_SERVER['PHP_AUTH_USER'] === $this->_config->username 
			&& $_SERVER['PHP_AUTH_PW'] === $this->_config->password
		)
		{
			return;
		}
		
		$response = (new Response\Html)
			->setHeader('WWW-Authenticate',
				sprintf('Basic realm="%s"', $this->_config->realm))
			->setHttpCode(401);
			
		$this->getController()->setDispatched(true);
	}
}
