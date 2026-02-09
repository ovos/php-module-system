<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Functions;
use Ovos\Password;
use Ovos\Response;
use Ovos\Stream;
use Ovos\Service\Cache as Service;
use Ovos\Service\Events;
use Ovos\Service\Memory;
use Throwable;

use function base64_decode;
use function base64_encode;
use function date;
use function function_exists;
use function http_build_query;
use function md5;
use function method_exists;
use function opcache_reset;
use function sprintf;

/**
 * Cache
 *
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
		$this->app->getConfig()->system->profilers->append->http = false;
	}
	
	/**
	 * Clears cache
	 */
	public function clear(): void
	{
		Functions::println('Clearing cache...' . PHP_EOL);
		
		$this->clearPerishable();
		$this->clearPersistent();
		$this->clearOpCache();
	}
	
	/**
	 * php cli.php system cache collect-garbage
	 */
	public function collectGarbage(
		bool $coloredOutput = false,
	): void
	{
		$cache = $this->container
			->get(Service::SYMBOL);
		if($cache->isEnabled() === false)
		{
			return;
		}
		
		if($cache->getPersistent()->getStore()->collectGarbage())
		{
			Functions::println('<green>Successfully collected garbage in persistent cache.<reset>', true);
		}
		else
		{
			Functions::println('<red>Error collecting garbage in persistent cache.<reset>', true);
		}
	}
	
	/**
	 * Clear perishable
	 */
	public function clearPerishable(): void
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
	
	/**
	 * Clear perishable (has to be called via http) 
	 */
	public function clearPerishableHttp(): bool
	{
		$memory = $this->container
			->get(Memory::SYMBOL);
		// clear apcu, this only has an effect when the tool is called via http
		return $memory->getStore()->clear();
	}
	
	/**
	 * Clear persistent
	 */
	public function clearPersistent(): void
	{
		/** @var Service $cache */
		$cache = $this->container
			->get(Service::SYMBOL);
		if($cache->isEnabled() === false)
		{
			Functions::println(
				'<purple>Persistent cache is not active.<reset>',
				true,
			);
		}
		else
		{
			if(($store = $cache->getStore()) === null)
			{
				return;
			}
			
			// reloading libraries
			$store->loadLibraries(true);
			Functions::println(
				'<green>Persistent cache libraries reloaded.<reset>',
				true,
			);
			
			if($store->clear() !== false)
			{
				Functions::println(
					'<green>Persistent cache cleared.<reset>',
					true,
				);
			}
			else
			{
				Functions::println(
					'<red>Error clearing persistent cache.<reset>',
					true,
				);
			}
		}
	}
	
	/**
	 * Clear OPcache
	 */
	public function clearOpCache(): void
	{
		if(function_exists('opcache_reset') === false)
		{
			Functions::println(
				'<purple>OPcache is not active.<reset>',
				true,
			);
		}
		else
		{
			// clear http pool
			if($this->callHttp('clearOpCacheHttp'))
			{
				Functions::println(
					'<green>OPcache cleared.<reset>',
					true,
				);
			}
			else
			{
				Functions::println(
					'<red>Error clearing OPcache. Try again!<reset>',
				);
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
	 * Calls HTTP method
	 */
	public function callHttp(
		string $method,
	): bool
	{
		// CLI application, no need to call HTTP
		if(SYSTEM_HOST === null)
		{
			return true;
		}
		
		try
		{
			$callUrl = SYSTEM_HOST . SYSTEM_PATH
				. 'cache-call-http.php'
				. '?' . http_build_query([
					'method' => $method,
					'access_token_hash' => base64_encode($this->getAccessTokenHash()),
				]);
			
			$contextOptions = [
				'http' => [
					'timeout' => 10,
					'header' => [],
				],
				'ssl' => [
					'verify_peer' => false, // for dev certificates
					'verify_peer_name' => false, // for dev certificates
				],
			];
			
			// HTTP Auth from config
			if(($httpAuth = $this->app->getConfig()->http_auth)
				&& $httpAuth->enabled)
			{
				$auth = base64_encode(sprintf('{%s}:{%s}',
					$httpAuth->username,
					$httpAuth->password,
				));
				
				$contextOptions['http']['header'][] =
					'Authorization: Basic ' . $auth;
			}
			
			// clear the method via http
			$request = new Stream\Request(
				$callUrl,
				$contextOptions,
			);
			
			/*
			$curl = curl_init();
			curl_setopt_array($curl, [
				CURLOPT_TIMEOUT => 10,
				CURLOPT_CONNECTTIMEOUT => 1,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_SSL_VERIFYHOST => false, // for dev certificates
				CURLOPT_SSL_VERIFYPEER => false, // for dev certificates
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
			*/
			
			return $request->invoke()->getResponse() === '1';
		}
		catch(Throwable $throwable)
		{
			$this->container
				->get(Events::SYMBOL)
				->log($throwable);
			
			$message = sprintf("<red>HTTP service not reachable:<reset>\n%s", $throwable->getMessage());
			Functions::println($message, true);
		}
		
		return false;
	}
	
	/**
	 * Consumes HTTP method call
	 */
	public function consumeHttpCall(): void
	{
		$response = new Response\Html;
		
		// verify token
		$accessTokenHash = isset($_GET['access_token_hash'])
			? base64_decode($_GET['access_token_hash'])
			: null;
		if($accessTokenHash === null)
		{
			$response->setHttpCode(403);
			exit;
		}
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
	
	public function getAccessToken(): string
	{
		return SYSTEM_HOST
			. date('Y-m-d')
			. md5(__FILE__);
	}
	
	public function getAccessTokenHash(): string
	{
		return Password::hash($this->getAccessToken());
	}
}
