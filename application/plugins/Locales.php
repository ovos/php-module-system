<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
use Ovos\Locales as BaseLocales;
use Ovos\Translator;
use Override;

/**
 * Locales
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Locales extends Plugin
{
	public const string SYMBOL = 'locales';
	
	#[Override]
	public function preDispatch(): void
	{
		// update lock state of each locale for current controller
		// this is needed to render appropriate locale switch possibilities for the user
		$locales = BaseLocales::getAll();
		foreach($locales as $locale)
		{
			$locale->setLocked($locale->isLockedForRequest($this->request));
		}
		
		// set translator locale to request locale
		$locale = $this->request->getLocale();
		Translator::setCurrentLocale($locale);
		
		// if the current locale is locked, use default instead of the translator
		// this ensures that a locale which is locked is never rendered = user does not see a missing translations
		// do not change the request locale, so that the choice of user is maintained even when translation for this controller is locked
		if($locale->isLocked())
		{
			Translator::setCurrentLocale(BaseLocales::getDefault());
		}
	}
}
