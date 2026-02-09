<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
use Ovos\Translator;

/**
 * Vendor
 *
 * @package Plugins
 * @author Marcin Gil <mg@ovos.at>
 */
class Vendor extends Plugin
{
	/**
	 * @var string
	 */
	public const string SYMBOL = 'vendor';
	
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
	public function __construct()
	{
		parent::__construct();
		
		if(($vendor = $this->_app->getConfig()->vendor) === null)
		{
			return;
		}
		
		$modules = $this->_app->getConfig()->system->modules;
		if($modules === null)
		{
			return;
		}
		
		foreach($modules as $module)
		{
			if($module->translations && $module->vendor)
			{
				Translator::addTranslationsPath
				(
					BASE_DIR
					. $module->path . DIRECTORY_SEPARATOR 
					. 'translations' . DIRECTORY_SEPARATOR
					. $vendor . DIRECTORY_SEPARATOR
				);
			}
		}
	}
}
