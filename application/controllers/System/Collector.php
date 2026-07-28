<?php
declare(strict_types=1);

namespace Controllers\System;

use FilesystemIterator;
use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Terminal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Collector
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Collector extends Controller\Cli
{
	use Controller\Traits\Cli;
	
	protected ?ArrayObject $collectors = null;
	
	public function __construct()
	{
		parent::__construct();
		
		$this->collectors = $this->app->getConfig()->system->collectors;
	}
	
	public function index(): void
	{
		if($this->collectors !== null)
		{
			foreach($this->collectors as $collector)
			{
				$controllerClassNs = Controller::NAMESPACE . $collector->controller;
				if(class_exists($controllerClassNs) === false)
				{
					$this->log('<red>Controller "%s" not found.',
						$collector->controller,
					);
					
					continue;
				}
				
				/** @var Controller\Cli $controller */
				$controller = new $controllerClassNs();
				if(method_exists($controller, $collector->action) === false)
				{
					$this->log('<red>Method "%s" not found on controller "%s".',
						$collector->action,
						$collector->controller,
					);
					
					continue;
				}
				
				$controller->{$collector->action}($collector);
			}
		}
	}
	
	public function collect(
		ArrayObject $config,
	): void
	{
		$this->collectLogs($config);
	}
	
	public function collectLogs(
		ArrayObject $config,
	): void
	{
		$this->log('Collecting <blue>logs<reset>...');
		
		$directory = BASE_DIR . 'application' . DIRECTORY_SEPARATOR
			. 'logs' . DIRECTORY_SEPARATOR;
		
		$affected = 0;
		
		$directoryIterator = new RecursiveDirectoryIterator(
			$directory,
			FilesystemIterator::SKIP_DOTS,
		);
		/**
		 * @var RecursiveDirectoryIterator $iterator
		 */
		foreach($iterator = new RecursiveIteratorIterator(
			$directoryIterator,
			RecursiveIteratorIterator::CHILD_FIRST,
		) as $file)
		{
			/**
			 * @var SplFileInfo $file
			 */
			if($file->isDir())
			{
				continue;
			}
			
			 // skip hidden files
			if(str_starts_with($file->getBasename(), '.'))
			{
				continue;
			}
			
			if($file->getExtension() !== 'txt')
			{
				continue;
			}
			
			if($file->getMTime() <= (time() - ($config->days * 24 * 60 * 60)))
			{
				printf('Deleting %s... ', $file->getBasename());
				
				$unlink = unlink($file->getPathname());
				if($unlink)
				{
					Terminal::output('<green>Done.');
					$affected++;
				}
				else
				{
					Terminal::output('<red>Error.');
				}
				
				print(PHP_EOL);
			}
		}
		
		$this->log('Deleted <blue>%d<reset> files.', $affected);
		$this->log('<green>Done.');
	}
}
