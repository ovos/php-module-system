<?php
declare(strict_types=1);

namespace Controllers;

use Composer\Autoload\ClassLoader;
use Ovos\Controller;
use Ovos\Response;
use Ovos\Terminal;
use Ovos\Terminal\Highlighter;
use Ovos\Translator;
use Ovos\Translator\Catalog;
use Ovos\Translator\Catalog\Exception;
use Ovos\Translator\Po\Entry;

use function array_keys;
use function basename;
use function class_exists;
use function count;
use function glob;
use function implode;
use function is_dir;
use function json_encode;
use function realpath;
use function rtrim;
use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use const BASE_DIR;
use const DIRECTORY_SEPARATOR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;

/**
 * Translations - the project's .po and .mo files against the code, as
 * Poedit's "Update from code" sees it
 *
 *   php cli.php translations update
 *   php cli.php translations check
 *
 * Covers the *.po files of every registered translations path (each module
 * with `translations: true`) that is not in a Composer vendor directory: a
 * package's catalogs are its own. Each .po's header says where the code is
 * and which calls translate. Both exit with 1 when something is out of line
 * or there is nothing to cover.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Translations extends Controller\Cli
{
	/**
	 * Brings every .po in line with the code, writes it and its .mo where
	 * they changed, and prints what was added, made obsolete or revived; a
	 * refused .po is left as it is
	 */
	public function update(): Response
	{
		$response = new Response\Cli;
		$catalog = new Catalog;
		[$files, $failed] = $this->getPoFiles();
		
		foreach($files as $poPath)
		{
			try
			{
				$changes = $catalog->update($poPath);
			}
			catch(Exception $exception)
			{
				$failed++;
				$this->output(sprintf('<white>%s<reset>: <red>refused, nothing written<reset> (%s)',
					Highlighter::sanitize($this->getRelativePath($poPath)),
					Highlighter::sanitize($exception->getMessage()),
				));
				
				continue;
			}
			
			$written = [];
			foreach($changes['written'] as $path)
			{
				$written[] = basename($path);
			}
			
			$this->output(sprintf(
				'<white>%s<reset>: %d added, %d made obsolete, %d revived; %s',
				Highlighter::sanitize($this->getRelativePath($poPath)),
				count($changes['added']),
				count($changes['obsolete']),
				count($changes['revived']),
				$written === []
					? 'nothing written'
					: 'wrote ' . Highlighter::sanitize(implode(', ', $written)),
			));
			
			$this->outputEntries('<green>added<reset>', $changes['added']);
			$this->outputEntries('<yellow>made obsolete<reset>', $changes['obsolete']);
			$this->outputEntries('<blue>revived<reset>', $changes['revived']);
		}
		
		if($failed > 0
			|| $files === [])
		{
			exit(1); // exit with error status
		}
		
		return $response;
	}
	
	/**
	 * Prints every problem of every .po and its .mo, and exits with 1 when
	 * there is one
	 */
	public function check(): Response
	{
		$response = new Response\Cli;
		$catalog = new Catalog;
		[$files, $total] = $this->getPoFiles();
		
		foreach($files as $poPath)
		{
			foreach($catalog->check($poPath) as $problem)
			{
				$total++;
				$this->output(sprintf('<white>%s<reset>: <red>%s<reset>',
					Highlighter::sanitize($this->getRelativePath($problem->file)),
					Highlighter::sanitize($problem->getDescription()),
				));
			}
		}
		
		if($files === [])
		{
			exit(1); // exit with error status
		}
		
		if($total > 0)
		{
			$this->output(sprintf('<red>%d problem%s<reset> in %d file%s',
				$total,
				$total === 1 ? '' : 's',
				count($files),
				count($files) === 1 ? '' : 's',
			));
			
			exit(1); // exit with error status
		}
		
		$this->output(sprintf('<green>No problems<reset> in %d file%s',
			count($files),
			count($files) === 1 ? '' : 's',
		));
		
		return $response;
	}
	
	/**
	 * The project's .po files, and how many registered paths are missing.
	 * Prints the paths it skips or misses, and says so when no .po is left.
	 *
	 * @return array{0: string[], 1: int}
	 */
	protected function getPoFiles(): array
	{
		$vendors = [];
		if(class_exists(ClassLoader::class))
		{
			foreach(array_keys(ClassLoader::getRegisteredLoaders()) as $vendor)
			{
				$vendors[] = $this->getComparable((string)realpath($vendor));
			}
		}
		
		$files = [];
		$missing = 0;
		foreach(Translator::getTranslationsPaths() as $path)
		{
			$relative = Highlighter::sanitize($this->getRelativePath($path));
			if(is_dir($path) === false)
			{
				$missing++;
				$this->output(sprintf('<white>%s<reset>: <red>registered, but not there<reset>', $relative));
				
				continue;
			}
			
			$real = $this->getComparable((string)realpath($path));
			foreach($vendors as $vendor)
			{
				if($vendor !== '' && str_starts_with($real, $vendor . '/'))
				{
					$this->output(sprintf('<white>%s<reset>: <gray>skipped, a package\'s own<reset>', $relative));
					
					continue 2;
				}
			}
			
			foreach(glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '*.po') ?: [] as $file)
			{
				$files[] = $file;
			}
		}
		
		if($files === [])
		{
			$this->output('<red>No .po file of the project<reset> in its registered translations paths (a module with `translations: true`)');
		}
		
		sort($files);
		
		return [$files, $missing];
	}
	
	/**
	 * A path to compare with another: "/" for every separator, lower case
	 * on Windows, where the case does not matter
	 */
	protected function getComparable(
		string $path,
	): string
	{
		$path = rtrim(str_replace('\\', '/', $path), '/');
		
		return DIRECTORY_SEPARATOR === '\\'
			? strtolower($path)
			: $path;
	}
	
	/**
	 * A path below the application's root, as a reader of the output knows it
	 */
	protected function getRelativePath(
		string $path,
	): string
	{
		$path = str_replace('\\', '/', $path);
		$base = str_replace('\\', '/', BASE_DIR);
		
		return str_starts_with($path, $base)
			? substr($path, strlen($base))
			: $path;
	}
	
	/**
	 * @param Entry[] $entries
	 */
	protected function outputEntries(
		string $label,
		array $entries,
	): void
	{
		foreach($entries as $entry)
		{
			$text = $entry->context === null
				? ''
				: $this->quote($entry->context) . ' | ';
				
			$this->output('  ' . $label . ' ' . Highlighter::sanitize($text . $this->quote($entry->msgid)));
		}
	}
	
	protected function quote(
		string $text,
	): string
	{
		return (string)json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
	
	protected function output(
		string $line,
	): void
	{
		Terminal::output($line . PHP_EOL, $this->usesColor());
	}
}
