<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Response;
use Ovos\ArrayObject;
use Ovos\Terminal;
use Ovos\Test;
use Ovos\Test\Internal;
use Ovos\Test\Runner;
use Ovos\Dir;
use Ovos\Terminal\Formatter;
use Ovos\Console\Table;
use Ovos\View;
use SplFileInfo;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function Ovos\services;
use function strlen;
use function is_dir;
use function substr;
use function array_map;

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
	 * @var string
	 */
	public const METHOD_ATTRIBUTE_INTERNAL = Internal::class;
	
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
	 * @param ?string $class (can be also used to specify the class::method as string)
	 * @param ?string $method
	 * 
	 * @return Response
	 */
	public function run(?string $class = null, ?string $method = null): Response
	{
		$response = new Response\Cli;
		
		$runners = $this->getTestRunners();
		$ran = [];
		$passed = [];
		$failed = [];
		$skipped = [];
		// class::method mode
		$classMethodMode = $class !== null && str_contains($class, '::');
		$classMode = $class !== null;
		
		foreach($runners as $runner)
		{
			/**
			* @var Runner $runner
			*/
			if($classMethodMode)
			{
				if($runner->__toString() !== $class)
				{
					continue;
				}
			}
			else if($classMode)
			{
				if($runner->method->class !== $class)
				{
					continue;
				}
			}
			
			// it's possible to filter only by the method too
			if($method && $runner->method->name !== $method)
			{
				continue;
			}
			
			try
			{
				/**
				 * @var Runner $runner
				 */
				$result = match($runner->run())
				{
					Test::RESULT_PASSED => $passed[] = $runner,
					Test::RESULT_FAILED => $failed[] = $runner,
					Test::RESULT_SKIPPED => $skipped[] = $runner,
				};
				
				$ran[] = $runner;
			}
			catch(Throwable $throwable)
			{
				$ran[] = $runner;
				$failed[] = $runner;
			}
		}
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Test (' . count($ran) . ')', 'Time', 'Memory', 'Result', 'Reason']);
		
		foreach($ran as $runner)
		{
			if($runner->test === null)
			{
				continue;
			}
			
			/**
			 * @var Runner $runner
			 */
			$table->addRow([
				$runner->__toString(),
				$runner->measurement->getTotalTime(),
				$runner->measurement->getTotalMemory(),
				$this->_formatResult($runner->test->result),
				$runner->test->reason,
			]);
			//$table->addRow([PHP_EOL]);
		}
		
		$response->append(PHP_EOL . $table->getTable());
		
		$table = new Table;
		$table->hasMarkup(true);
		$table->setHeaders(['Tests',
			$this->_formatResult(Test::RESULT_PASSED),
			$this->_formatResult(Test::RESULT_FAILED),
			$this->_formatResult(Test::RESULT_SKIPPED),
		]);
		$table->addRow([
			count($ran),
			count($passed),
			count($failed),
			count($skipped),
		]);
		$response->append(PHP_EOL . $table->getTable()
			. PHP_EOL . PHP_EOL);
		$response->send(); // flush before we display errors
		
		foreach($failed as $runner)
		{
			Terminal::output(sprintf(
				'Test <white>%s<reset> has <red>failed<reset>...',
				$runner->__toString()) . PHP_EOL
			, true);
			
			if($runner->test === null)
			{
				continue;
			}
			
			if($runner->test->throwable === null)
			{
				continue;
			}
			
			$this->_displayThrowable($throwable);
		}
		
		if(count($failed))
		{
			exit(1); // exit with error status
		}
		
		return $response;
	}
	
	/**
	 * @return array
	 */
	public function getTestRunners(): array
	{
		$runners = [];
		
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
					if($method->isConstructor()
						|| $method->isDestructor()
					)
					{
						continue;
					}
					
					$methodAttributes = $method->getAttributes();
					$methodAttributesArray = array_map(fn($attribute) => $attribute->getName(), $methodAttributes);
					if(in_array(self::METHOD_ATTRIBUTE_INTERNAL, $methodAttributesArray, true))
					{
						continue;
					}
					
					$runners[] = new Runner($class, $method);
				}
			}
		}
		
		return $runners;
	}

	/**
	 * @param int $result
	 *
	 * @return string
	 */
	protected function _formatResult(int $result): string
	{
		$markup = match($result)
		{
			Test::RESULT_PASSED		=> '<green>Passed<reset>',
			Test::RESULT_FAILED		=> '<red>Failed<reset>',
			Test::RESULT_SKIPPED	=> '<blue>Skipped<reset>',
		};
		
		return Formatter::handleMarkup($markup);
	}
	
	/**
	 * @param Throwable $throwable
	 *
	 * @return void
	 */
	public function _displayThrowable(Throwable $throwable): void
	{
		$view = new View('events.phtml');
		$view->renderTitle = false;
		$view->events = [$throwable];
		
		echo $view->render(), PHP_EOL;
	}
}
