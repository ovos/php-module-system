<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Encryptor;

/**
 * Tools
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Tools extends Controller\Cli
{
	use Controller\Traits\Cli;
	
	public function encrypt(
		?string $string = null,
	): void
	{
		if($string === null)
		{
			$this->log('Please paste the string to encrypt:');
			$string = $this->readLine();
		}
		
		$config = $this->app->getConfig()->encryption;
		$encryptor = new Encryptor($config->key, $config->method);
		$encrypted = $encryptor->encrypt($string);
		
		$this->log($encrypted);
	}
	
	public function decrypt(
		?string $string = null,
	): void
	{
		if($string === null)
		{
			$this->log('Please paste the string to decrypt:');
			$string = $this->readLine();
		}
		
		$config = $this->app->getConfig()->encryption;
		$encryptor = new Encryptor($config->key);
		$decrypted = $encryptor->decrypt($string);
		
		$this->log($decrypted);
	}
}
