<?php
declare(strict_types=1);

namespace Plugins;

use Ovos\ArrayObject;
use Ovos\Controller\Plugin;
use Ovos\Container\Inject;
use Ovos\Container\ArrayObject as InjectArrayObject;
use Ovos\Translator;

/**
 * Vendor
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Vendor extends Plugin
{
	public const string SYMBOL = 'vendor';
	
	protected ?string $vendor;
	
	protected ?ArrayObject $modules;
	
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
		$this->vendor = $vendor;
		
		if($modules === null)
		{
			return;
		}
		$this->modules = $modules;
		
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
