<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\ArrayObject;
use Ovos\Response;
use Ovos\Migration\Runner;
use Ovos\Dir;
use Ovos\Terminal\Formatter;
use Ovos\Console\Table;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ReflectionClass;
use ReflectionMethod;
use function strlen;

/**
 * Migrations
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Migrations extends Controller\Cli
{
	/**
	 * @var string
	 */
	public const MIGRATION_EXT = '.php';

	/**
	 * @var ArrayObject
	 */
	protected ArrayObject $_paths;
	
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		$this->_paths = $this->_app->getConfig()->system->migrations;
	}
	
	/**
	 * Runs migrations
	 * 
	 * @param int $amount
	 * 
	 * @return Response
	 */
	public function run(int $amount = 1): Response
	{
		$response = new Response\Cli;

		$migrations = $this->getMigrations();
		foreach($migrations as $migration)
		{
			/**
			 * @var Runner $migration
			 */
			$migration->run();
		}

		return $response;
	}
	
	/**
	 * Rolls back migrations
	 * 
	 * @param int $amount
	 * 
	 * @return Response
	 */
	public function rollback(int $amount = 1): Response
	{
		$response = new Response\Cli;

		$migrations = $this->getMigrations();
		foreach($migrations as $migration)
		{
			/**
			 * @var Runner $migration
			 */
			//$migration->rollback();
		}

		return $response;
	}

	/**
	 * @return array
	 */
	public function getMigrations(): array
	{
		$migrations = [];	
	
		foreach($this->_paths as $path)
		{
			$path = Dir::preProcess($path);
			if(is_dir(BASE_DIR . $path) === false)
			{
				continue;
			}
			
			$pathLength = strlen(BASE_DIR . $path);
			$iterator = new RecursiveDirectoryIterator(BASE_DIR . $path, FilesystemIterator::SKIP_DOTS);
			foreach(new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST) as $file)
			{
				/**
				* @var SplFileInfo $file
				*/
				if($file->isDir())
				{
					continue;
				}
				
				// hidden files, eg. ".gitkeep"
				if($file->getBasename()[0] === '.')
				{
					continue;
				}
				
				// not a migration file
				if('.' . $file->getExtension() !== self::MIGRATION_EXT)
				{
					continue;
				}
				
				// include the migration, because filename is not psr-4 compatible
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				$filename = $file->getBasename(self::MIGRATION_EXT);
				[$id, $filename] = explode('_', $filename);
				
				$className = 'Migrations' . $relativePath . '\\' . $filename;
				$class = new ReflectionClass($className);
		
				$migrations[] = new Runner($class, $id);
			}			
		}
		
		return $migrations;
	}
}
