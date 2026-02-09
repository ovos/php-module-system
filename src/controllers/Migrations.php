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
use Ovos\Migration;
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
	public const MIGRATION_EXT = 'php';

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
			$migration->run(Migration::DIRECTION_UP);
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
			$migration->run(Migration::DIRECTION_DOWN);
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
			$path = Dir::preProcess($path, true);
			if(is_dir($path = BASE_DIR . $path) === false)
			{
				continue;
			}
			
			$pathLength = strlen($path);
			$files = Dir::getFiles($path, function($file)
			{
				/**
				* @var SplFileInfo $file
				*/
				// filter out non .php files
				if($file->getExtension() !== self::MIGRATION_EXT)
				{
					return null;
				}
				
				return $file->getBasename('.' . self::MIGRATION_EXT);
			});
			
			foreach($files as $basename => $file)
			{
				// include the migration, because filename is not psr-4 compatible
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				[$id, $filename] = explode('_', $basename);
				
				$className = 'Migrations' . $relativePath . '\\' . $filename;
				$class = new ReflectionClass($className);
		
				$migrations[] = new Runner($class, (int)$id);
			}			
		}
		
		return $migrations;
	}
}
