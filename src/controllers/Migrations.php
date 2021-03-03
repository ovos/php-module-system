<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\ArrayObject;
use Ovos\Pdo\Expression;
use Ovos\Response;
use Ovos\Migration\Runner;
use Ovos\Dir;
use Ovos\Terminal;
use Ovos\Terminal\Formatter;
use Ovos\Console\Table;
use Ovos\Migration;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ReflectionClass;
use ReflectionMethod;
use Stores\Migrations as Store;
use Models\Migration as Model;
use Throwable;
use function strlen;

/**
 * Migrations
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Migrations extends Controller\Cli
{
	use Controller\Traits\Cli;
	
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
		$this->setColoredOutput(true);
		
		$this->_paths = $this->_app->getConfig()->system->migrations;
	}
	
	/**
	 * Runs migrations
	 * 
	 * @param ?int $amount
	 * 
	 * @return Response
	 */
	public function run(?int $amount = null): Response
	{
		$response = new Response\Cli;

		$store = new Store;
		$records = $store->getAll();
		$migrations = $this->getMigrations();
		$migrated = [];
		
		foreach($migrations as $id => $migration)
		{
			if(isset($records[$id])
				&& $records[$id]->migrated_at !== null)
			{
				continue;
			}
			
			if($amount !== null && count($migrated) >= $amount)
			{
				break;
			}
			
			Terminal::output(sprintf(
				'<green>Migrating <white>%s<reset>... ',
				$migration->__toString())
			, true);
			
			/**
			 * @var Runner $migration
			 */
			$migration->run(Migration::DIRECTION_UP);
			
			$record = isset($records[$id])
				? $records[$id] // rolledback
				: new Model; // new
			$record->id = $id;
			$record->name = $migration->__toString();
			$record->migrated_at = new Expression('NOW()');
			$record->rolledback_at = null;
			$record->save();
			
			Terminal::output('done.' . PHP_EOL);
			
			$migrated[$id] = $migration;
		}
		
		$this->responseSummary($response, $migrated);

		return $response;
	}
	
	/**
	 * Rolls back migrations
	 * 
	 * @param ?int $amount
	 * 
	 * @return Response
	 */
	public function rollback(?int $amount = null): Response
	{
		$response = new Response\Cli;

		$store = new Store;
		$records = $store->getAll();
		$migrations = $this->getMigrations(reverse: true);
		$migrated = [];
		
		foreach($migrations as $id => $migration)
		{
			if(!isset($records[$id]))
			{
				continue;
			}
			
			$record = $records[$id];
			if($record->migrated_at === null)
			{
				continue;
			}
					
			if($amount !== null && count($migrated) >= $amount)
			{
				break;
			}
			
			Terminal::output(sprintf(
				'<red>Rolling back <white>%s<reset>... ',
				$migration->__toString())
			, true);
			
			/**
			 * @var Runner $migration
			 */
			$migration->run(Migration::DIRECTION_DOWN);
			
			$record->migrated_at = null;
			$record->rolledback_at = new Expression('NOW()');
			$record->save();
			
			Terminal::output('done.' . PHP_EOL);			
			
			$migrated[$id] = $migration;
		}
		
		$this->responseSummary($response, $migrated);

		return $response;
	}
	
	/**
	 * @param Response\Cli $response
	 * @param array $migrated
	 */
	public function responseSummary(Response\Cli $response, array $migrated): void
	{
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Migration ('. count($migrated) .')', 'ID', 'Time', 'Memory']);
			
		foreach($migrated as $id => $migratedRunner)
		{
			/**
			 * @var Runner $migratedRunner
			 */
			$table->addRow([
				$migratedRunner->__toString(),
				$id,
				$migratedRunner->measurement->getTotalTime(),
				$migratedRunner->measurement->getTotalMemory(),
			]);
		}
	
		$response->append(PHP_EOL . $table->getTable());
	}

	/**
	 * @param bool $reverse
	 * 
	 * @return array
	 */
	public function getMigrations(bool $reverse = false): array
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
		
				$migrations[$id] = new Runner($class, (int)$id);
			}			
		}
		
		if($reverse)
		{
			krsort($migrations);
		}
		else
		{
			ksort($migrations);
		}
		
		return $migrations;
	}
}
