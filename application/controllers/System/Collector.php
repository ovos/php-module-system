<?php
declare(strict_types=1);

namespace Controllers\System;

use FilesystemIterator;
use Ovos\ArrayObject;
use Ovos\Controller;
use Ovos\Service\Cache;
use Ovos\Service\Events;
use Ovos\Terminal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function class_exists;
use function method_exists;
use function printf;
use function str_starts_with;
use function time;
use function unlink;

use const BASE_DIR;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

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
		$this->collectCache($config);
	}
	
	/**
	 * Prunes the persistent cache ahead of its expiry (see KeyValue\Redis::collectGarbage(): the versioned
	 * stores drop the items their rules made outdated). "cache: no" in the collector's entry skips it, and
	 * so does a project whose cache is off or has no persistent connection.
	 */
	public function collectCache(
		ArrayObject $config,
	): void
	{
		if($config->cache === false)
		{
			$this->log('Collecting <blue>cache<reset> skipped: the entry says <blue>cache: no<reset>.');
			
			return;
		}
		
		$cache = $this->cacheService();
		if($cache === null)
		{
			$this->log('Collecting <blue>cache<reset> skipped: the cache is disabled.');
			
			return;
		}
		
		// No persistent connection configured: nothing to collect, and the store could not be built
		$persistent = $cache->getPersistent();
		if($persistent->getConfig()->persistent?->connection === null)
		{
			$this->log('Collecting <blue>cache<reset> skipped: no persistent cache connection is configured.');
			
			return;
		}
		
		$this->log('Collecting <blue>cache<reset>...');
		
		// the number of entries dropped, true when the store keeps nothing to collect, false on an error;
		// a store that throws is logged, and the collectors after this one still run
		try
		{
			$collected = $persistent->getStore()
				->collectGarbage();
		}
		catch(Throwable $throwable)
		{
			// into the error log too: the collector runs from a cron nobody watches
			$this->container
				->resolve(Events::SYMBOL)
				?->log($throwable);
			$this->log('<red>Collecting the cache failed: %s', $throwable->getMessage());
			
			return;
		}
		
		if($collected === false)
		{
			$this->log('<red>Collecting the cache failed (see the error log).');
			
			return;
		}
		
		if($collected === true)
		{
			$this->log('Nothing to collect.');
		}
		else
		{
			$this->log('Dropped <blue>%d<reset> cache entries.', $collected);
		}
		$this->log('<green>Done.');
	}
	
	/**
	 * The cache service; null when the cache is disabled, or no cache service is registered
	 */
	protected function cacheService(): ?Cache
	{
		$cache = $this->container
			->resolve(Cache::SYMBOL);
		
		return $cache instanceof Cache
			? $cache
			: null;
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
