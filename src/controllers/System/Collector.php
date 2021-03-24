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
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Collector extends Controller\Cli
{
	use Controller\Traits\Cli;

	/**
	 * @var null|ArrayObject
	 */
	protected null|ArrayObject $_collectors = null;

	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_collectors = $this->_app->getConfig()->system->collectors;
	}

	/**
	 * @param bool $coloredOutput
	 */
	public function index(bool $coloredOutput = false): void
	{
		if($this->_collectors !== null)
		{
			foreach($this->_collectors as $collector)
			{
				$controllerClassNs = 'Controllers\\' . $collector->controller;
				if(class_exists($controllerClassNs) === false)
				{
					$this->log('<red>Controller "%s" not found.',
						$collector->controller);
						
					continue;		
				}
				
				/** @var Controller $controller */
				$controller = new $controllerClassNs();
				$controller->setColoredOutput($coloredOutput); // trait method
				
				if(method_exists($controller, $collector->action) === false)
				{
					$this->log('<red>Method "%s" not found on controller "%s".',
						$collector->action, $collector->controller);
						
					continue;
				}
				
				$controller->{$collector->action}($collector);
			}
		}
	}

	/**
	 * @param ArrayObject $config
	 */
	public function collect(ArrayObject $config): void
	{
		$this->collectLogs($config);
	}

	/**
	 * @param ArrayObject $config
	 */
	public function collectLogs(ArrayObject $config): void
	{
		$this->log('Collecting <blue>logs<reset>...');
		
		$directory = BASE_DIR . 'application' . DIRECTORY_SEPARATOR
			. 'logs' . DIRECTORY_SEPARATOR;
		
		$affected = 0;
		
		$iterator = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
		foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
		{
			/**
			 * @var SplFileInfo $file
			 */
			if($file->isDir())
			{
				continue;
			}
			
			if(str_starts_with($file->getBasename(), '.')) // skip hidden files
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
