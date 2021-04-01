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
use Ovos\Console\Table;
use Ovos\Migration;
use SplFileInfo;
use ReflectionClass;
use Stores\Migrations as Store;
use Models\Migration as Model;
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
		
		$this->_paths = $this->_app->getConfig()->system->migrations;
	}
	
	/**
	 * Displays summary of migrations
	 * 
	 * @return Response
	 */
	public function status(): Response
	{
		$response = new Response\Cli;
		
		$this->_summary($response);
	
		return $response;
	}

	/**
	 * @param ?int $amount
	 * @aliasof run()
	 *
	 * @return Response
	 */
	public function migrate(?int $amount = null): Response
	{
		return $this->run($amount);
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
		
		$this->_actionSummary($response, $migrated);
		$this->_summary($response);

		return $response;
	}
	
	/**
	 * Rolls back migrations
	 * 
	 * @param ?int $amount
	 * 
	 * @return Response
	 */
	public function rollback(?int $amount = 1): Response
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
			if($store->tableExists()) // for case when we delete our migrations table
			{
				$record->save();
			}
			
			Terminal::output('done.' . PHP_EOL);			
			
			$migrated[$id] = $migration;
		}
		
		$this->_actionSummary($response, $migrated);
		$this->_summary($response);

		return $response;
	}
	
	/**
	 * @param Response\Cli $response
	 * @param array $migrated
	 */
	protected function _actionSummary(Response\Cli $response, array $migrated): void
	{
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Migrations affected (' . count($migrated) . ')', 'Name', 'Time', 'Memory']);
			
		foreach($migrated as $id => $migratedRunner)
		{
			/**
			 * @var Runner $migratedRunner
			 */
			$table->addRow([
				$id,
				$migratedRunner->__toString(),
				$migratedRunner->measurement->getTotalTime(),
				$migratedRunner->measurement->getTotalMemory(),
			]);
		}
	
		$response->append(PHP_EOL . $table->getTable());
	}
	
	/**
	 * @param Response\Cli $response
	 */
	protected function _summary(Response\Cli $response): void
	{
		$store = new Store;
		$records = $store->getAll(options: ['order' => ['id', 'DESC']]);
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Migrations (' . count($records) . ')', 'Name', 'Migrated at', 'Rolled back at']);
			
		foreach($records as $id => $record)
		{
			/**
			 * @var Model $record
			 */
			$table->addRow([
				$record->id,
				$record->name,
				$record->migrated_at,
				$record->rolledback_at,
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
			$files = Dir::getFiles($path, skipCallback: function($file)
			{
				/**
				* @var SplFileInfo $file
				*/
				// filter out non .php files
				return $file->getExtension() !== self::MIGRATION_EXT;
			});
			
			foreach($files as $file)
			{
				// include the migration, because filename is not psr-4 compatible
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				$basename = $file->getBasename('.' . self::MIGRATION_EXT);
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
