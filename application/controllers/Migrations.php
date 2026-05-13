<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\ArrayObject;
use Ovos\Console\Table;
use Ovos\Dir;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Migration;
use Ovos\Migration\Runner;
use Ovos\Pdo\Expression;
use Ovos\Response;
use Ovos\Terminal;
use SplFileInfo;
use ReflectionClass;
use Stores\Migrations as Store;
use Models\Migration as Model;

use function array_shift;
use function explode;
use function implode;
use function is_dir;
use function krsort;
use function strlen;
use function substr;

/**
 * Migrations
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Migrations extends Controller\Cli
{
	public const string EXT = 'php';
	
	/**
	 * @var string[]
	 */
	protected array $configPath = ['system', 'migrations'];
	
	public const int SUMMARY_LIMIT = 20;
	
	protected ArrayObject $paths;
	
	public function __construct()
	{
		parent::__construct();
		
		if(($paths = $this->app->getConfig()
			->getPath($this->configPath)) === null)
		{
			throw new MissingConfigException(
				'This tool requires an existing config path: "%s".',
				implode('.', $this->configPath),
			);
		}
		
		$this->paths = $paths;
	}
	
	/**
	 * Displays a summary of migrations
	 */
	public function status(): Response
	{
		$response = new Response\Cli;
		
		$this->summary($response);
		
		return $response;
	}
	
	/**
	 * @aliasof run()
	 */
	public function migrate(
		?int $amount = null,
	): Response
	{
		return $this->run($amount);
	}
	
	/**
	 * Run migrations
	 */
	public function run(
		?int $amount = null,
	): Response
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
				? $records[$id] // rolled back
				: new Model; // new
			$record->id = $id;
			$record->name = $migration->__toString();
			$record->migrated_at = new Expression('NOW()');
			$record->rolled_back_at = null;
			$record->save();
			
			Terminal::output('done.' . PHP_EOL);
			
			$migrated[$id] = $migration;
		}
		
		$this->actionSummary($response, $migrated);
		$this->summary($response);
		
		return $response;
	}
	
	/**
	 * Rolls back migrations
	 */
	public function rollback(
		?int $amount = 1,
	): Response
	{
		$response = new Response\Cli;
		
		$store = new Store;
		$records = $store->getAll();
		$migrations = $this->getMigrations(reverse: true);
		$migrated = [];
		
		foreach($migrations as $id => $migration)
		{
			if(isset($records[$id]) === false)
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
			$record->rolled_back_at = new Expression('NOW()');
			if($store->tableExists()) // for case when we delete our migrations table
			{
				$record->save();
			}
			
			Terminal::output('done.' . PHP_EOL);
			
			$migrated[$id] = $migration;
		}
		
		$this->actionSummary($response, $migrated);
		$this->summary($response);
		
		return $response;
	}
	
	protected function actionSummary(
		Response\Cli $response,
		array $migrated,
	): void
	{
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders([
			'Migrations affected (' . count($migrated) . ')',
			'Name',
			'Time',
			'Memory',
		]);
		
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
	
	protected function summary(
		Response\Cli $response,
	): void
	{
		$store = new Store;
		$records = $store->getAll(options: [
			'order' => ['id DESC'],
			'limit' => self::SUMMARY_LIMIT,
		]);
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Migrations (last ' . count($records) . ')',
			'Name',
			'Migrated at',
			'Rolled back at',
		]);
		
		foreach($records as $id => $record)
		{
			/**
			 * @var Model $record
			 */
			$table->addRow([
				$record->id,
				$record->name,
				$record->migrated_at,
				$record->rolled_back_at,
			]);
		}
		
		$response->append(PHP_EOL . $table->getTable());
	}
	
	public function getMigrations(
		bool $reverse = false,
	): array
	{
		$migrations = [];
		
		foreach($this->paths as $path)
		{
			$path = Dir::preProcess($path, true);
			if(is_dir($path = BASE_DIR . $path) === false)
			{
				continue;
			}
			
			$pathLength = strlen($path);
			$files = Dir::getFiles($path, skipCallback: static function($file)
			{
				/**
				* @var SplFileInfo $file
				*/
				// filter out non .php files
				return $file->getExtension() !== self::EXT;
			}, filter: Dir::FILTER_FILES);
			
			foreach($files as $file)
			{
				// include the migration because the filename is not psr-4 compatible
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				$namespace = str_replace('/', '\\', $relativePath);
				$basename = $file->getBasename('.' . self::EXT);
				$fileNameParts = explode('_', $basename);
				$id = array_shift($fileNameParts);
				$filename = implode('_', $fileNameParts);
				
				$className = 'Migrations' . $namespace . '\\' . $filename;
				$class = new ReflectionClass($className);
				
				$migrations[$id] = $this->container
					->injectClass(Runner::class, [
						'class' => $class,
						'id' => (int)$id,
					],
				);
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
