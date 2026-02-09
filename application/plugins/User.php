<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Container\Inject;
use Ovos\Controller\Plugin;
use Ovos\Service\Auth as AuthService;
use Models\User as Model;

use function Ovos\services;

/**
 * User
 *
 * @package Plugins
 * @author Marcin Gil <mg@ovos.at>
 */
class User extends Plugin
{
	/**
	 * @var string
	 */
	public const string SYMBOL = 'user';
	
	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return self::SYMBOL;
	}
	
	/**
	 * @var ?AuthService
	 */
	protected ?AuthService $_authService = null;
	
	/**
	 * @param AuthService $authService
	 */
	public function __construct(
		#[Inject(AuthService::SYMBOL)] ?AuthService $authService,
	)
	{
		parent::__construct();
		
		$this->_authService = $authService;
	}
	
	/**
	 * @return ?Model
	 */
	public function user(): ?Model
	{
		if($this->_authService === null)
		{
			return null;
		}
		
		return $this->_authService->getUser();
	}
}
