<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
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
	public const SYMBOL = 'user';

	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return self::SYMBOL;
	}
	
	/**
	 * @return null|Model
	 */
	public function user(): ?Model
	{
		return services()->auth->getUser();
	}
}
