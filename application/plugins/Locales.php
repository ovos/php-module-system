<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
use Ovos\Locales as BaseLocales;
use Ovos\Translator;

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
	public const string SYMBOL = 'locales';
	
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
		
		// set translator locale to request locale
		$locale = $this->_request->getLocale();
		Translator::setCurrentLocale($locale);
		
		// if current locale is locked, use default instead for the translator
		// this ensures that a locale which is locked is never rendered = user does not see a missing translations
		// do not change the request locale, so that the choice of user is maintained even when translation for this controller is locked
		if($locale->isLocked())
		{
			Translator::setCurrentLocale(BaseLocales::getDefault());
		}
	}
}
