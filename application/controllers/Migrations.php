<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\ArrayObject;
use Ovos\Terminal\Highlighter;
use Ovos\Terminal\Table;
use Ovos\Dir;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Migration;
use Ovos\Migration\Runner;
use Ovos\Migration\Sql;
use Ovos\Pdo\Expression;
use Ovos\Response;
use Ovos\Terminal;
use SplFileInfo;
use ReflectionClass;
use Stores\Migrations as Store;
use Models\Migration as Model;

use function array_shift;
use function count;
use function explode;
use function file_exists;
use function implode;
use function is_dir;
use function krsort;
use function str_ends_with;
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
	 * A migration with no PHP work in it is its two SQL halves and nothing
	 * else: the `_up.sql` is then the file discovery keys on, and the class
	 * it runs through is Ovos\Migration\Sql.
	 */
	public const string EXT_SQL = 'sql';
	
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
				'<green>Migrating <reset>%s... ',
				Highlighter::className($migration->__toString()))
			, $this->usesColor());
			
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
				'<red>Rolling back <reset>%s... ',
				Highlighter::className($migration->__toString()))
			, $this->usesColor());
			
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
		$table->setHeaders([
			Highlighter::header('Migrations affected (' . count($migrated) . ')'),
			Highlighter::header('Name'),
			Highlighter::header('Time'),
			Highlighter::header('Memory'),
		]);
		$table->setAlignments([
			2 => Table::ALIGN_RIGHT,
			3 => Table::ALIGN_RIGHT,
		]);
		
		foreach($migrated as $id => $migratedRunner)
		{
			/**
			 * @var Runner $migratedRunner
			 */
			$table->addRow([
				Highlighter::color((string)$id, 'gray'),
				Highlighter::className($migratedRunner->__toString()),
				// schema changes take their time, so seconds are the scale
				Highlighter::time($migratedRunner->measurement->getTotalTime(), 1.0, 5.0),
				Highlighter::memory($migratedRunner->measurement->getTotalMemory(), 8388608, 33554432),
			]);
		}
		
		$response->append(PHP_EOL . Terminal::getMessage(
			$table->getTable(),
			$response->getColoredOutput(),
		));
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
		$table->setHeaders([
			Highlighter::header('Migrations (last ' . count($records) . ')'),
			Highlighter::header('Name'),
			Highlighter::header('Migrated at'),
			Highlighter::header('Rolled back at'),
		]);
		
		foreach($records as $id => $record)
		{
			/**
			 * @var Model $record
			 */
			$table->addRow([
				Highlighter::color((string)$record->id, 'gray'),
				Highlighter::className((string)$record->name),
				// applied vs. rolled back: the state is the column worth reading
				Highlighter::color((string)$record->migrated_at, 'darkgreen'),
				Highlighter::color((string)$record->rolled_back_at, 'brown'),
			]);
		}
		
		$response->append(PHP_EOL . Terminal::getMessage(
			$table->getTable(),
			$response->getColoredOutput(),
		));
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
				$extension = $file->getExtension();
				if($extension === self::EXT)
				{
					return false;
				}
				
				// a migration with no class of its own is discovered by its
				// up half; the down half is only ever the sidecar
				return $extension !== self::EXT_SQL
					|| str_ends_with($file->getBasename('.' . self::EXT_SQL), '_down');
			}, filter: Dir::FILTER_FILES);
			
			foreach($files as $file)
			{
				$sql = $file->getExtension() === self::EXT_SQL;
				
				$basename = $sql
					// <id>_<Name>_up -> <id>_<Name>
					? substr($file->getBasename('.' . self::EXT_SQL), 0, -3)
					: $file->getBasename('.' . self::EXT);
				
				$relativePath = substr($file->getPath(), $pathLength);
				$namespace = str_replace('/', '\\', $relativePath);
				$fileNameParts = explode('_', $basename);
				$id = array_shift($fileNameParts);
				$filename = implode('_', $fileNameParts);
				
				// the name the record carries, whether or not there is a
				// class to be named after it: turning a migration into SQL
				// alone must not rewrite the row it already has
				$className = 'Migrations' . $namespace . '\\' . $filename;
				
				if($sql)
				{
					$base = $file->getPath() . DIRECTORY_SEPARATOR . $basename;
					
					// a class beside it wins: a migration that has one has
					// it for a reason, and it claims the same id
					if(file_exists($base . '.' . self::EXT))
					{
						continue;
					}
					
					$migrations[$id] = $this->container
						->injectClass(Runner::class, [
							'class' => new ReflectionClass(Sql::class),
							'id' => (int)$id,
							'name' => $className,
							'sqlBase' => $base,
						],
					);
					
					continue;
				}
				
				// include the migration because the filename is not psr-4 compatible
				include_once($file->getPathname());
				
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
