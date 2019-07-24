<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Encryptor;
use Ovos\Functions;
use Ovos\Exception;
use Ovos\Password;
use Ovos\Response;
use Ovos\View;
use function Ovos\services;

/**
 * Tools
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Tools extends Controller\Cli
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
	public function clearCache(): void
	{
		Functions::println('Clearing cache ...' . PHP_EOL);
		
		// clear the apcu via http
		if($this->clearHttpCache('apcu'))
		{
			Functions::println('<green>Perishable cache cleared.<reset>', true);
		}
		else
		{
			Functions::println('<red>Error clearing perishable cache.<reset>', true);
		}		
		
		$persistent = services()->cache;
		if($persistent->isEnabled() === false)
		{
			Functions::println('<red>Persistent cache is not active.<reset>', true);
		}
		else
		{
			if($persistent->getPool()->clear())
			{
				Functions::println('<green>Persistent cache cleared.<reset>', true);
			}
			else
			{
				Functions::println('<red>Error clearing persistent cache.<reset>', true);
			}
		}

		// clear the opcache via http
		if($this->clearHttpCache('apcu'))
		{
			Functions::println('<green>Opcache cleared.<reset>', true);
		}
		else
		{
			
			Functions::println('<red>Error clearing opcache. Try again!<reset>');
		}
	}
	
	/**
	 * @param string $cache
	 */
	public function clearHttpCache($cache) 
	{
		// clear the cache via http
		$curl = curl_init();
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_URL => SYSTEM_HOST . SYSTEM_PATH . 'clear-' . $cache . '.php?token=' . $this->getAccessToken()
		]);
		$response = curl_exec($curl);
		curl_close($curl);
		
		return $response === '1';
	}

	/**
	 *  Clears apcu
	 */
	public function clearApcu(): bool
	{
		// clear apcu, this only has an effect when tool is called via http
		$memory = services()->memory;
		if($memory->isEnabled())
		{
			return $memory->getPool()->clear();
		}

		return false;
	}
	
	/**
	 *  Clears opcache
	 */
	public function clearOpcache(): bool
	{
		// clear opcache, this only has an effect when tool is called via http
		if(\function_exists('opcache_reset'))
		{
			opcache_reset();

			return true;
		}

		return false;
	}

	/**
	 * @return string
	 */
	public function getAccessToken(): string
	{
		return (string)password_hash(SYSTEM_HOST . date('Y-m-d'), PASSWORD_BCRYPT);
	}
	
	/**
	 * @param string $string (optional)
	 */
	public function encrypt(string $string = null): void
	{
		if($string === null)
		{
			$this->log('Please paste the string to encrypt:');
			$string = $this->readLine();
		}
		
		$config = $this->_app->getConfig()->system->encryption;
		$encryptor = new Encryptor($config->key, $config->method);
		$encrypted = $encryptor->encrypt($string);
		
		$this->log($encrypted);
	}
	
	/**
	 * @param string $string (optional)
	 */
	public function decrypt(string $string = null): void
	{
		if($string === null)
		{
			$this->log('Please paste the string to decrypt:');
			$string = $this->readLine();
		}
		
		$config = $this->_app->getConfig()->system->encryption;
		$encryptor = new Encryptor($config->key);
		$decrypted = $encryptor->decrypt($string);
		
		$this->log($decrypted);
	}
}