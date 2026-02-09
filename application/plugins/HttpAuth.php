<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Controller\Plugin;
use Ovos\Client;
use Ovos\Response;
use Override;

use function in_array;

/**
 * HttpAuth
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class HttpAuth extends Plugin
{
	public const string SYMBOL = 'http_auth';
	
	protected ?ArrayObject $config;
	
	public function __construct(
		#[Inject('config')]
		#[InjectArrayObject('http_auth')]
		?ArrayObject $config,
	)
	{
		parent::__construct();
		
		$this->config = $config;
	}
	
	#[Override]
	public function preDispatch(): void
	{
		if($this->config === null)
		{
			return;
		}
		
		// allow clients listed in the whitelist
		if($this->config->whitelist !== null)
		{
			$clientIp = Client::getIp();
			$whitelist = $this->config->whitelist->getArrayCopy();
			
			if(in_array($clientIp, $whitelist, true))
			{
				return;
			}
		}
		
		if($this->config->enabled === false
			|| empty($this->config->username))
		{
			return;
		}
		
		if(isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])
			&& $_SERVER['PHP_AUTH_USER'] === $this->config->username 
			&& $_SERVER['PHP_AUTH_PW'] === $this->config->password
		)
		{
			return;
		}
		
		$response = (new Response\Html)
			->setHeader('WWW-Authenticate',
				sprintf('Basic realm="%s"', $this->config->realm))
			->setHttpCode(401);
			
		$this->getController()->setDispatched(true);
	}
}
