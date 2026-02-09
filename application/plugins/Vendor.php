<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\Controller\Plugin;
use Ovos\Translator;
use Ovos\ArrayObject;
use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;

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
	 * @var ?string
	 */
	protected ?string $_vendor;
	
	/**
	 * @var ?ArrayObject
	 */
	protected ?ArrayObject $_modules;
	
	/**
	 * @param ?string $vendor
	 * @param ?ArrayObject $modules
	 */
	public function __construct(
		#[Inject('config')]
		#[InjectArrayObject('vendor')]
		?string $vendor,
		#[Inject('config')]
		#[InjectArrayObject('system', 'modules')]
		?ArrayObject $modules,
	)
	{
		parent::__construct();
		
		if($vendor === null)
		{
			return;
		}
		$this->_vendor = $vendor;
		
		if($modules === null)
		{
			return;
		}
		$this->_modules = $modules;
		
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
