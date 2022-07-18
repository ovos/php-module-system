<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Response;
use Ovos\ArrayObject;
use Ovos\Terminal;
use Ovos\Test\Runner;
use Ovos\Dir;
use Ovos\Terminal\Formatter;
use Ovos\Console\Table;
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
		
		$this->_paths = $this->_app->getConfig()->system->tests;
	}
	
	/**
	 * Runs tests
	 * Specify class or method to narrow the pool
	 * 
	 * @param ?string $class
	 * @param ?string $method
	 * 
	 * @return Response
	 */
	public function run(?string $class = null, ?string $method = null): Response
	{
		$response = new Response\Cli;
		
		$tests = $this->getTests();
		$ran = [];
		$passed = 0;
		$failed = 0;
		
		foreach($tests as $test)
		{
			/**
			 * @var Runner $test
			 */
			if($class && $test->method->class !== 'Tests\\' . $class)
			{
				continue;
			}
			
			if($method && $test->method->name !== $method)
			{
				continue;
			}
			
			try
			{
				/**
				 * @var Runner $test
				 */
				$test->run() ? $passed++ : $failed++;
				$ran[] = $test;
			}
			catch(Throwable $throwable)
			{
				Terminal::output(sprintf(
					'Test <white>%s<reset> has <red>failed<reset>...' . PHP_EOL,
					$test->__toString())
				, true);
				
				fwrite(STDERR, sprintf('Test %s has failed...', $test->__toString()) . PHP_EOL);
				
				throw $throwable;
			}
		}
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Test (' . count($ran) . ')', 'Time', 'Memory', 'Result']);
			
		foreach($ran as $test)
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
			count($ran),
			$passed,
			$failed,
		]);
		$response->append(PHP_EOL . $table->getTable());
		
		if($failed)
		{
			exit(1); // exit with error status
		}

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
			$files = Dir::getFiles($path, skipCallback: function($file)
			{
				/**
				* @var SplFileInfo $file
				*/
				// filter out non .php files
				return $file->getExtension() !== self::TEST_EXT;
			});
			
			foreach($files as $file)
			{
				// include the test, composer is always excluding /tests from psr-4 autoloader
				include_once($file->getPathname());
				
				$relativePath = substr($file->getPath(), $pathLength);
				$namespace = str_replace('/', '\\', $relativePath);
				$basename = $file->getBasename('.' . self::TEST_EXT);
				$className = 'Tests' . $namespace . '\\' . $basename;
				$class = new ReflectionClass($className);
				$methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
				
				foreach($methods as $method)
				{
					if($method->isConstructor() || $method->isDestructor())
					{
						continue;
					}
					
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
