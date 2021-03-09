<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Response;
use Ovos\Size;
use Ovos\ArrayObject;
use Ovos\Terminal;
use Ovos\Test\Runner;
use Ovos\Dir;
use Ovos\Terminal\Formatter;
use Ovos\Console\Table;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use function strlen;

/**
 * Tests
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Tests extends Controller\Cli
{
	use Controller\Traits\Cli;

	/**
	 * @var string
	 */
	public const TEST_EXT = 'php';

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
				
		$this->_paths = $this->_app->getConfig()->system->tests;
	}
	/**
	 * Runs tests
	 * 
	 * @return Response
	 */
	public function run(): Response
	{
		$response = new Response\Cli;
		
		$tests = $this->getTests();
		$countPassed = 0;
		$countFailed = 0;
		
		foreach($tests as $test)
		{
			try
			{
				/**
				 * @var Runner $test
				 */
				$test->run() ? $countPassed++ : $countFailed++;
			}
			catch(Throwable $throwable)
			{
				Terminal::output(sprintf(
					'Test <white>%s<reset> has <red>failed<reset>...' . PHP_EOL,
					$test->__toString())
				, true);
				
				throw $throwable;
			}
		}
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Test (' . count($tests) . ')', 'Time', 'Memory', 'Result']);
			
		foreach($tests as $test)
		{
			/**
			 * @var Runner $test
			 */
			$table->addRow([
				$test->__toString(),
				$test->measurement->getTotalTime(),
				$test->measurement->getTotalMemory(),
				$this->_formatResult($test->result), 
			]);
			//$table->addRow([PHP_EOL]);
		}
	
		$response->append(PHP_EOL . $table->getTable());
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Tests',
			Formatter::handleMarkup('<green>Passed<reset>'),
			Formatter::handleMarkup('<red>Failed<reset>')
		]);
		$table->addRow([
			count($tests),
			$countPassed,
			$countFailed,
		]);
		$response->append(PHP_EOL . $table->getTable());

		return $response;
	}
	
	/**
	 * @return array
	 */
	public function getTests(): array
	{
		$tests = [];	
	
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
				if($file->getExtension() !== self::TEST_EXT)
				{
					return null;
				}
				
				return $file->getBasename('.' . self::TEST_EXT);
			});
			
			foreach($files as $basename => $file)
			{
				// include the test, composer is always excluding /tests from psr-4 autoloader
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				$className = 'Tests' . $relativePath . '\\' . $basename;
				$class = new ReflectionClass($className);
				$methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
				
				foreach($methods as $method)
				{
					$tests[] = new Runner($class, $method);
				}
			}			
		}
		
		return $tests;
	}

	/**
	 * @param bool $result
	 *
	 * @return string
	 */
	protected function _formatResult(bool $result): string
	{
		return Formatter::handleMarkup($result ? '<green>Pass<reset>' : '<red>Fail<reset>');
	}
}
