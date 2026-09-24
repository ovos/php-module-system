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
use Ovos\Terminal\Highlighter;
use Ovos\Terminal\Table;
use Ovos\View;
use SplFileInfo;
use ReflectionClass;
use Throwable;

use function count;
use function implode;
use function is_dir;
use function str_contains;
use function str_replace;
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
		$table->setHeaders([
			Highlighter::header($this->header . ' (' . count($runners) . ')'),
			Highlighter::header('Time (s)'),
			Highlighter::header('Memory'),
			Highlighter::header('Result'),
			Highlighter::header('Reason'),
		]);
		$table->setAlignments([
			1 => Table::ALIGN_RIGHT,
			2 => Table::ALIGN_RIGHT,
		]);
		
		foreach($results as $result)
		{
			/**
			 * @var Runner $runner
			 */
			$row = [Highlighter::className($result->__toString())];
			$row[] = Highlighter::time($result->measurement?->getTotalTime());
			$row[] = Highlighter::memory($result->measurement?->getTotalMemory());
			
			$row[] = $this->formatResult($result->getResult());
			// most likely failed on __construct. A failure's reason is the
			// message to read; a skip's is only bookkeeping, so it stays dim
			$row[] = Highlighter::color(
				$result->reason ?? $result->throwable?->getMessage(),
				$result->getResult() === Result::RESULT_FAILED
					? 'red'
					: 'gray',
			);
			
			$table->addRow($row);
		}
		
		$response->append(PHP_EOL . Terminal::getMessage(
			$table->getTable(),
			$response->getColoredOutput(),
		));
		
		$table = new Table;
		$table->setHeaders([
			Highlighter::header('Total'),
			$this->formatResult(Result::RESULT_PASSED),
			$this->formatResult(Result::RESULT_FAILED),
			$this->formatResult(Result::RESULT_COMPLETED),
			$this->formatResult(Result::RESULT_SKIPPED),
		]);
		$table->addRow([
			Highlighter::color((string)count($results), 'white'),
			$this->formatCount($resultsGrouped[Result::RESULT_PASSED], Result::RESULT_PASSED),
			$this->formatCount($resultsGrouped[Result::RESULT_FAILED], Result::RESULT_FAILED),
			$this->formatCount($resultsGrouped[Result::RESULT_COMPLETED], Result::RESULT_COMPLETED),
			$this->formatCount($resultsGrouped[Result::RESULT_SKIPPED], Result::RESULT_SKIPPED),
		]);
		$response->append(PHP_EOL . Terminal::getMessage(
			$table->getTable(),
			$response->getColoredOutput(),
		) . PHP_EOL . PHP_EOL);
		$response->send(); // flush before we display errors
		
		foreach($resultsGrouped[Result::RESULT_FAILED] as $result)
		{
			Terminal::output(sprintf(
				'%s %s has <red>failed<reset>...',
				$this->header,
				Highlighter::className($result->__toString())) . PHP_EOL
			, $this->usesColor());
			
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
	
	/**
	 * Whether a path relative to a tests root lies under a `files` directory —
	 * helpers and fixtures the runner must include only by an explicit require
	 */
	public static function isHelperPath(
		string $relative,
	): bool
	{
		return str_contains('/' . str_replace('\\', '/', $relative), '/files/');
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
			$files = Dir::getFiles($path, skipCallback: static function($file) use ($pathLength)
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
				
				// filter out files under a `files` directory (helpers, fixtures): the
				// callback sees FILES, never a directory, so the test is whether a
				// `files` segment sits in the path BELOW the tests root — the root's
				// own path may contain one; separators normalised for Windows
				if(self::isHelperPath(substr($file->getPathname(), $pathLength)))
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
	
	/**
	 * Returns the label as markup, left for Terminal\Table to resolve or strip
	 * — resolving it here would put raw ANSI into a redirected run's log
	 */
	protected function formatResult(
		string $result,
	): string
	{
		return match($result)
		{
			Result::RESULT_PASSED		=> '<green>Passed<reset>',
			Result::RESULT_FAILED		=> '<red>Failed<reset>',
			Result::RESULT_COMPLETED	=> '<blue>Completed<reset>',
			Result::RESULT_SKIPPED		=> '<gray>Skipped<reset>',
		};
	}
	
	/**
	 * Colorizes a tally in its result's color, but only once it counts: a red
	 * "0" under Failed reads as an alarm where there is none
	 */
	protected function formatCount(
		array $results,
		string $result,
	): string
	{
		return Highlighter::tally((string)count($results), match($result)
		{
			Result::RESULT_PASSED => 'green',
			Result::RESULT_FAILED => 'red',
			Result::RESULT_COMPLETED => 'blue',
			default => 'gray',
		});
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
