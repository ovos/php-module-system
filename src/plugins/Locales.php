<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
use Ovos\Locales as BaseLocales;

/**
 * Locales
 *
 * @package Plugins
 * @author Marcin Gil <mg@ovos.at>
 */
class Locales extends Plugin
{
	/**
	 * @var string
	 */
	public const SYMBOL = 'locales';
	
	/**
	 * @return string
	 */
	public static function getSymbol(): string
	{
		return self::SYMBOL;
	}

	/**
	 * @return void
	 */
	public function preDispatch(): void
	{
		// update lock state of each locale for current controller
		// this is needed for render appropriate locale switch possibilities for the user
		$locales = BaseLocales::getAll();
		foreach($locales as $locale)
		{
			$locale->setLocked($locale->isLockedForRequest($this->_request));
		}
		
		// if current locale is locked, use default instead
		// this ensures that a locale which is locked is never rendered = user does not see missing translations
		$locale = $this->_request->getLocale();
		if($locale->isLocked())
		{
			$this->_request->setLocale(BaseLocales::getDefault());
		}
	}
}
