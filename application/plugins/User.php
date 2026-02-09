<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Container\Inject;
use Ovos\Controller\Plugin;
use Ovos\Service\Auth as AuthService;
use Models\User as Model;

/**
 * User
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class User extends Plugin
{
	public const string SYMBOL = 'user';
	protected ?AuthService $authService = null;
	
	public function __construct(
		#[Inject(AuthService::SYMBOL)] ?AuthService $authService,
	)
	{
		parent::__construct();
		
		$this->authService = $authService;
	}
	
	/**
	 * @return ?Model
	 */
	public function user(): ?Model
	{
		if($this->authService === null)
		{
			return null;
		}
		
		return $this->authService->getUser();
	}
}
