<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Encryptor;
use Ovos\Functions;
use Ovos\Exception;
use Ovos\Password;
use Ovos\Response;
use Ovos\Response\Html;
use Ovos\View;
use function Ovos\services;
use Throwable;

/**
 * Cache
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Cache extends Controller\Cli
{
	use Controller\Traits\Cli;

	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		// override
		$this->_app->getConfig()->system->profilers->append->http = false;
	}

	/**
	 * Clears cache
	 */
	public function clear(): void
	{
		Functions::println('Clearing cache ...' . PHP_EOL);
		
		$this->clearPerishable();
		$this->clearPersistent();
		$this->clearOpCache();
	}
	
	/**
	 * Clear perishable
	 */
	public function clearPerishable(): void
	{
		if(services()->memory->isEnabled() === false)
		{
			Functions::println('<purple>Perishable cache is not active.<reset>', true);
		}
		else
		{
			// clear http pool
			if($this->callHttp('clearPerishableHttp'))
			{
				Functions::println('<green>Perishable cache cleared.<reset>', true);
			}
			else
			{
				Functions::println('<red>Error clearing perishable cache.<reset>', true);
			}	
		}	
	}
	
	/**
	 * Clear perishable (has to be called via http) 
	 */
	public function clearPerishableHttp(): bool
	{
		// clear apcu, this only has an effect when tool is called via http
		return services()->memory->getPool()->clear();
	}	
	
	/**
	 * Clear persistent
	 */
	public function clearPersistent(): void
	{
		$persistent = services()->cache;
		if($persistent->isEnabled() === false)
		{
			Functions::println('<purple>Persistent cache is not active.<reset>', true);
		}
		else
		{
			// clear common pool
			if(($pool = $persistent->getPool()) && $pool->clear())
			{
				Functions::println('<green>Persistent cache cleared.<reset>', true);
			}
			else
			{
				Functions::println('<red>Error clearing persistent cache.<reset>', true);
			}
		}
	}
	
	/**
	 * Clear OPcache
	 */
	public function clearOpCache(): void
	{
		if(\function_exists('opcache_reset') === false)
		{
			Functions::println('<purple>OPcache is not active.<reset>', true);
		}
		else
		{
			// clear http pool
			if($this->callHttp('clearOpCacheHttp'))
			{
				Functions::println('<green>OPcache cleared.<reset>', true);
			}
			else
			{
				
				Functions::println('<red>Error clearing OPcache. Try again!<reset>');
			}
		}	
	}
	
	/**
	 * Clear OPcache (has to be called via http) 
	 */
	public function clearOpCacheHttp(): bool
	{
		// clear opcache, this only has an effect when tool is called via http
		return opcache_reset();
	}
	
	/**
	 * Calls http method
	 * 
	 * @param string $method
	 */
	public function callHttp($method) 
	{
		try
		{
			// clear the method via http
			$curl = curl_init();
			curl_setopt_array($curl, [
				CURLOPT_TIMEOUT => 10,
				CURLOPT_CONNECTTIMEOUT => 1,
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_URL => SYSTEM_HOST . SYSTEM_PATH
					. 'cache-call-http.php'
					. '?' . http_build_query([
						'method' => $method,
						'access_token_hash' => base64_encode($this->getAccessTokenHash()),
					])
			]);
			
			$response = curl_exec($curl);
			if(curl_errno($curl))
			{
				throw new Exception(curl_error($curl));
			}
			curl_close($curl);
			
			return $response === '1';
		}
		catch(Throwable $throwable)
		{
			services()->events->log($throwable);
		
			$message = sprintf("<red>HTTP service not reachable:<reset>\n%s", $throwable->getMessage());
			Functions::println($message, true);
		}
	}
	
	/**
	 * Consumes http method call
	 */
	public function consumeHttpCall(): void
	{
		$response = new Response\Html;
		
		// verify token
		$accessTokenHash = isset($_GET['access_token_hash'])
			? base64_decode($_GET['access_token_hash'])
			: null;
		$accessToken = $this->getAccessToken();
		
		if(password_verify($accessToken, $accessTokenHash) === false)
		{
			$response->setHttpCode(403);
			exit;
		}
		
		// get method
		$method = $_GET['method'] ?? null;
		if(method_exists($this, $method) === false)
		{
			$response->setHttpCode(403);
			exit;
		}
		
		$response
			->set((string)$this->{$method}())
			->send();
	}

	/**
	 * @return string
	 */
	public function getAccessToken(): string
	{
		return SYSTEM_HOST
			. date('Y-m-d')
			. md5(__FILE__);
	}
	
	/**
	 * @return string
	 */
	public function getAccessTokenHash(): string
	{
		return Password::hash($this->getAccessToken());
	}
}
