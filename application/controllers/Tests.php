<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Exception\MissingException\MissingConfigException;
use Ovos\Response;
use Ovos\ArrayObject;
use Ovos\Terminal;
use Ovos\Test\Result;
use Ovos\Test\Runner;
use Ovos\Dir;
use Ovos\Terminal\Formatter;
use Ovos\Terminal\Table;
use Ovos\View;
use SplFileInfo;
use ReflectionClass;
use Throwable;

use function implode;
use function is_dir;
use function str_contains;
use function strlen;
use function substr;

/**
 * Tests
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Tests extends Controller\Cli
{
	public const string EXT = 'php';
	
	/**
	 * @var string[]
	 */
	protected array $configPath = ['system', 'tests'];
	
	protected ArrayObject $paths;
	
	protected string $header = 'Test';
	
	protected string $namespace = 'Tests';
	
	/**
	 */
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
	 * Runs tests
	 * Specify class or method to narrow the pool
	 */
	public function run(
		?string $class = null, // can be also used to specify the class::method as string
		?string $method = null,
	): Response
	{
		$response = new Response\Cli;
		
		$runners = $this->getTestRunners();
		$results = [];
		$resultsGrouped = [
			Result::RESULT_COMPLETED => [],
			Result::RESULT_PASSED => [],
			Result::RESULT_FAILED => [],
			Result::RESULT_SKIPPED => [],
		];
		
		foreach($runners as $runner)
		{
			/**
			* @var Runner $runner
			*/
			if($class !== null)
			{
				if(str_contains($class, '::'))
				{
					[$class, $method] = explode('::', $class);
					$runner
						->filterClass($class)
						->filterMethod($class);
				}
				else
				{
					$runner->filterClass($class);
				}
			}
			if($method !== null)
			{
				$runner->filterMethod($method);
			}
			
			/**
			 * @var Runner $runner
			 */
			foreach($runner->run() as $result)
			{
				$results[] = $result;
				$resultsGrouped[$result->result][] = $result;
			}
		}
		
		// erase the last in-progress status line before the results table
		Terminal::clearLine();
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders([
			$this->header . ' (' . count($runners) . ')',
			'Time (s)',
			'Memory',
			'Result',
			'Reason',
		]);
		
		foreach($results as $result)
		{
			/**
			 * @var Runner $runner
			 */
			$row = [$result->__toString()];
			$row[] = $result->measurement?->getTotalTime();
			$row[] = $result->measurement?->getTotalMemory();
			
			$row[] = $this->formatResult($result->getResult());
			$row[] = $result->reason ?? ($result->throwable?->getMessage()); // most likely failed on __construct
			
			$table->addRow($row);
		}
		
		$response->append(PHP_EOL . $table->getTable());
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Total',
			$this->formatResult(Result::RESULT_PASSED),
			$this->formatResult(Result::RESULT_FAILED),
			$this->formatResult(Result::RESULT_COMPLETED),
			$this->formatResult(Result::RESULT_SKIPPED),
		]);
		$table->addRow([
			count($results),
			count($resultsGrouped[Result::RESULT_PASSED]),
			count($resultsGrouped[Result::RESULT_FAILED]),
			count($resultsGrouped[Result::RESULT_COMPLETED]),
			count($resultsGrouped[Result::RESULT_SKIPPED]),
		]);
		$response->append(PHP_EOL . $table->getTable()
			. PHP_EOL . PHP_EOL);
		$response->send(); // flush before we display errors
		
		foreach($resultsGrouped[Result::RESULT_FAILED] as $result)
		{
			Terminal::output(sprintf(
				'%s <white>%s<reset> has <red>failed<reset>...',
				$this->header,
				$result->__toString()) . PHP_EOL
			, true);
			
			if($result->throwable === null)
			{
				continue;
			}
			
			$this->displayThrowable($result->throwable);
		}
		
		if(count($resultsGrouped[Result::RESULT_FAILED]))
		{
			exit(1); // exit with error status
		}
		
		return $response;
	}
	
	public function getTestRunners(): array
	{
		$runners = [];
		
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
				if($file->getExtension() !== self::EXT)
				{
					return true;
				}
				
				// filter out files which are not test files
				if(str_ends_with($file->getBasename(self::EXT), '.file.'))
				{
					return true;
				}
				
				// filter out directories with files, which are not test files
				if(str_ends_with($file->getPathname(),
					DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
				))
				{
					return true;
				}
				
				return false;
			}, filter: Dir::FILTER_FILES);
			
			foreach($files as $file)
			{
				// include the test, composer is always excluding /tests from psr-4 autoloader
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				$namespace = str_replace('/', '\\', $relativePath);
				$basename = $file->getBasename('.' . self::EXT);
				$className = $this->namespace . $namespace . '\\' . $basename;
				
				$class = new ReflectionClass($className);
				$runners[] = $this->container
					->injectClass(Runner::class, [
						'class' => $class,
					],
				);
			}
		}
		
		return $runners;
	}
	
	protected function formatResult(
		string $result,
	): string
	{
		$markup = match($result)
		{
			Result::RESULT_PASSED		=> '<green>Passed<reset>',
			Result::RESULT_FAILED		=> '<red>Failed<reset>',
			Result::RESULT_COMPLETED	=> '<blue>Completed<reset>',
			Result::RESULT_SKIPPED		=> '<gray>Skipped<reset>',
		};
		
		return Formatter::handleMarkup($markup);
	}
	
	public function displayThrowable(
		Throwable $throwable,
	): void
	{
		$view = new View('events.phtml');
		$view->renderTitle = false;
		$view->events = [$throwable];
		
		echo $view->render(), PHP_EOL;
	}
}
