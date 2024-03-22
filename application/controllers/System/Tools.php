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
	 * @param ?string $string (optional)
	 */
	public function encrypt(?string $string = null): void
	{
		if($string === null)
		{
			$this->log('Please paste the string to encrypt:');
			$string = $this->readLine();
		}
		
		$config = $this->_app->getConfig()->encryption;
		$encryptor = new Encryptor($config->key, $config->method);
		$encrypted = $encryptor->encrypt($string);
		
		$this->log($encrypted);
	}
	
	/**
	 * @param ?string $string (optional)
	 */
	public function decrypt(?string $string = null): void
	{
		if($string === null)
		{
			$this->log('Please paste the string to decrypt:');
			$string = $this->readLine();
		}
		
		$config = $this->_app->getConfig()->encryption;
		$encryptor = new Encryptor($config->key);
		$decrypted = $encryptor->decrypt($string);
		
		$this->log($decrypted);
	}
}
