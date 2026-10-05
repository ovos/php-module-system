<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Exception\RuntimeException;
use Ovos\Functions;
use Ovos\Response;
use Ovos\Stream;
use Ovos\Service\Cache as Service;
use Ovos\Service\Events;
use Ovos\Service\Memory;
use Throwable;

use function abs;
use function base64_encode;
use function ctype_digit;
use function function_exists;
use function hash_equals;
use function hash_hmac;
use function http_build_query;
use function in_array;
use function method_exists;
use function opcache_reset;
use function sprintf;
use function time;

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
			$store->getFunctions()->loadLibraries(true);
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
		if(opcache_reset() === true)
		{
			return true;
		}
		
		// opcache_reset() answers FALSE while a restart is already pending, and
		// a pending restart is the outcome the caller asked for - the cache is
		// going to be cold. Reporting "Error clearing OPcache. Try again!" for
		// that taught everyone to ignore the line, which is worse than the
		// occasional false positive it was guarding against.
		$status = opcache_get_status(false);
		
		return ($status['restart_pending'] ?? false) === true
			|| ($status['restart_in_progress'] ?? false) === true;
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
		
		$signingKey = self::signingKey($this->encryptionKey());
		if($signingKey === null)
		{
			Functions::println('<red>HTTP cache call refused: no encryption.key is configured'
				. ' — set one per instance (ENCRYPTION[KEY] in .env).<reset>', true);
			
			return false;
		}
		
		$time = time();
		try
		{
			// the URL names the method and nothing else: the call is signed in
			// its headers, which no access log and no failed open's message
			// carries the way they carry a query string
			$callUrl = SYSTEM_HOST . SYSTEM_PATH
				. 'cache-call-http.php'
				. '?' . http_build_query(['method' => $method]);
			
			$contextOptions = [
				'http' => [
					'timeout' => 10,
					'header' => [
						self::TIME_HEADER . ': ' . $time,
						self::SIGNATURE_HEADER . ': ' . self::signature($signingKey, $method, $time),
					],
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
				$auth = base64_encode(sprintf('%s:%s',
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
			
			return $request->invoke()->getResponse() === '1';
		}
		catch(Throwable $throwable)
		{
			$reason = $throwable->getMessage();
			$this->container
				->get(Events::SYMBOL)
				->log(new RuntimeException('HTTP cache call failed: ' . $reason));
			
			$message = sprintf("<red>HTTP service not reachable:<reset>\n%s", $reason);
			Functions::println($message, true);
		}
		
		return false;
	}
	
	/**
	 * The only methods the HTTP self-call may invoke
	 */
	protected const array HTTP_METHODS = [
		'clearPerishableHttp',
		'clearOpCacheHttp',
	];
	
	/**
	 * Domain separation for the handshake HMAC
	 */
	protected const string HMAC_CONTEXT = 'ovos/system-cache/http-call';
	
	/**
	 * The headers a call is signed in — a header never lands in an access log
	 * or in a failed open's message the way a query string does
	 */
	protected const string TIME_HEADER = 'X-Ovos-Cache-Time';
	
	protected const string SIGNATURE_HEADER = 'X-Ovos-Cache-Signature';
	
	/**
	 * Seconds a signed call stays good either side of the receiver's clock —
	 * caller and receiver share the host's clock; the slack covers a slow call
	 */
	public const int SIGNATURE_WINDOW = 60;
	
	/**
	 * Consumes HTTP method call
	 */
	public function consumeHttpCall(): void
	{
		$response = new Response\Html;
		
		// Only the two methods this endpoint exists for. method_exists() is
		// true for PROTECTED methods as well, and $this->{$method}() runs in
		// class scope, so the gate used to open every zero-argument method on
		// this controller — the old getAccessToken() among them, which answered
		// with the token itself.
		$method = (string)($_GET['method'] ?? '');
		if(in_array($method, self::HTTP_METHODS, true) === false)
		{
			$response->setHttpCode(403);
			exit;
		}
		
		// The call is signed: an HMAC — constant work, compared in constant
		// time — never a password hash, whose cost the CALLER would choose (a
		// request could ask for argon2id at 128 MB, or bcrypt at cost 31).
		$signed = self::verifies(
			self::signingKey($this->encryptionKey()),
			$method,
			(string)($_SERVER['HTTP_X_OVOS_CACHE_TIME'] ?? ''),
			(string)($_SERVER['HTTP_X_OVOS_CACHE_SIGNATURE'] ?? ''),
			time(),
		);
		if($signed === false)
		{
			$response->setHttpCode(403);
			exit;
		}
		
		$response
			->set((string)$this->{$method}())
			->send();
	}
	
	/**
	 * The self-call's signing key: an HMAC of the context under the instance's
	 * encryption key. The secret is the KEY of the MAC — never part of a
	 * message — and the encryption key itself signs nothing. Null without an
	 * encryption key: a signature over public facts alone is anyone's to
	 * compute, so the call is refused on both sides rather than made.
	 */
	public static function signingKey(
		string $encryptionKey,
	): ?string
	{
		return $encryptionKey === ''
			? null
			: hash_hmac('sha256', self::HMAC_CONTEXT, $encryptionKey, true);
	}
	
	/**
	 * One call's signature: the method it may run and the second it was made
	 * — worthless for the other method, and once SIGNATURE_WINDOW has passed
	 */
	public static function signature(
		string $signingKey,
		string $method,
		int $time,
	): string
	{
		return hash_hmac('sha256', $method . '|' . $time, $signingKey);
	}
	
	/**
	 * Whether a call is signed: under this key, for this method, made within
	 * SIGNATURE_WINDOW of now
	 */
	public static function verifies(
		?string $signingKey,
		string $method,
		string $time,
		string $signature,
		int $now,
	): bool
	{
		if($signingKey === null
			|| ctype_digit($time) === false
			|| abs($now - (int)$time) > self::SIGNATURE_WINDOW)
		{
			return false;
		}
		
		return hash_equals(self::signature($signingKey, $method, (int)$time), $signature);
	}
	
	/**
	 * The instance's encryption key — config encryption.key, per install
	 */
	protected function encryptionKey(): string
	{
		return (string)($this->app->getConfig()
			->getPath(['encryption', 'key']) ?? '');
	}
}
