<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Functions;
use Ovos\Response\Json;
use Ovos\Service\Session;
use Ovos\Terminal\Table;
use ArrayObject as BaseArrayObject;

use function array_key_exists;
use function array_keys;
use function explode;
use function is_array;
use function is_bool;
use function json_encode;
use function preg_split;

use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Sessions
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Sessions extends Controller\Cli
{
	use Controller\Traits\Cli;
	
	/**
	 * Allows accessing specified CLI methods via HTTP
	 * (search returns session CONTENTS - projects gate the System
	 * controllers behind their admin auth, exactly like keep-alive)
	 */
	protected array $httpActions = [
		'keep-alive',
		'search',
	];
	
	/**
	 * Clears sessions
	 */
	public function clear(): void
	{
		Functions::println('Clearing sessions ...' . PHP_EOL);
		
		$session = $this->container
			->getClass(Session::class);
		if($session->flush())
		{
			Functions::println('<green>Sessions cleared.<reset>', true);
		}
		else
		{
			Functions::println('<red>Error clearing sessions.<reset>', true);
		}
	}
	
	/**
	 * Garbage-collects session leftovers that do not self-expire: the
	 * json handler's activity index (its documents and locks are
	 * collected by redis TTLs); a no-op under the php handler, whose
	 * native machinery collects its own garbage - safe to run from a cron
	 */
	public function gc(): void
	{
		Functions::println('Collecting session garbage ...' . PHP_EOL);
		
		$session = $this->container
			->getClass(Session::class);
		
		$removed = $session->gc();
		if($removed === null)
		{
			Functions::println('<yellow>Nothing to collect - the "'
				. $session->getHandler()
				. '" handler garbage-collects itself.<reset>', true);
			
			return;
		}
		
		Functions::println('<green>Removed ' . $removed
			. ' stale activity ' . ($removed === 1 ? 'entry' : 'entries')
			. ', ' . (int)$session->countActive()
			. ' active session(s) in the last 5 minutes.<reset>', true);
	}
	
	/**
	 * Searches the session documents through the configured RediSearch
	 * index - filters address the "session.index.fields" aliases (the
	 * field type decides the syntax: tag = exact, text = full-text,
	 * numeric = value or "min..max" range)
	 *
	 * CLI:  system sessions search "authenticated=true created=1783000000.." [limit] [offset] [sort] [order]
	 * HTTP: system/sessions/search?authenticated=true&created=1783000000..&limit=10&sort=created&order=desc
	 */
	public function search(
		?string $filters = null,
		int $limit = 10,
		int $offset = 0,
		?string $sort = null,
		?string $order = null,
	): ?Json
	{
		$session = $this->container
			->getClass(Session::class);
		$index = $session->index();
		
		if($this->request->isCli() === false)
		{
			$response = new Json;
			if($index === null)
			{
				return $response
					->failure('The session index is not enabled.', true)
					->setHttpCode(404);
			}
			
			$result = $index->search(
				$index->query($this->searchFilters()),
				(int)($this->request->get('limit') ?? $limit),
				(int)($this->request->get('offset') ?? $offset),
				$this->request->get('sort'),
				$this->request->get('order') !== 'desc',
			);
			
			$response->total = $result['total'];
			$response->sessions = $result['sessions'];
			
			return $response;
		}
		
		if($index === null)
		{
			Functions::println('<red>The session index is not enabled'
				. ' ("session.index.enabled: yes") - it also requires the'
				. ' session connection on redis DATABASE 0.<reset>', true);
				
			return null;
		}
		
		$result = $index->search(
			$index->query($this->parseFilters($filters)),
			$limit,
			$offset,
			$sort,
			$order !== 'desc',
		);
		
		Functions::println('<green>' . (int)$result['total']
			. '<reset> matching session(s)' . PHP_EOL);
			
		$fields = $this->indexFields();
		$table = new Table;
		$table->setHeaders(['session id', ...array_keys($fields)]);
		foreach($result['sessions'] as $sessionId => $document)
		{
			$row = [(string)$sessionId];
			foreach($fields as $path)
			{
				$row[] = $this->cell($document, $path);
			}
			$table->addRow($row);
		}
		Functions::println($table->getTable());
		
		return null;
	}
	
	/**
	 * "alias=value" pairs from the CLI, whitespace-separated
	 */
	protected function parseFilters(
		?string $filters,
	): array
	{
		$parsed = [];
		foreach(preg_split('/\s+/', (string)$filters, -1,
			PREG_SPLIT_NO_EMPTY) as $pair)
		{
			[$alias, $value] = explode('=', $pair, 2) + [1 => ''];
			$parsed[$alias] = $value;
		}
		
		return $parsed;
	}
	
	/**
	 * Every http query parameter named like a configured field alias
	 */
	protected function searchFilters(): array
	{
		$filters = [];
		foreach($this->indexFields() as $alias => $path)
		{
			$value = $this->request->get($alias);
			if($value !== null && $value !== '')
			{
				$filters[$alias] = $value;
			}
		}
		
		return $filters;
	}
	
	/**
	 * The configured index fields: alias => document path segments
	 */
	protected function indexFields(): array
	{
		$config = $this->app->getConfig()
			->getPath(['session', 'index', 'fields']);
			
		$fields = [];
		foreach($config ?? [] as $alias => $field)
		{
			// config iteration yields raw arrays - only offsetGet converts
			if($field instanceof BaseArrayObject)
			{
				$field = $field->getArrayCopy();
			}
			
			$path = $field['path'] ?? '';
			$fields[(string)$alias] = is_array($path) === true
				? $path
				: explode('.', (string)$path);
		}
		
		return $fields;
	}
	
	/**
	 * A document value along a path, rendered for the CLI table
	 */
	protected function cell(
		mixed $document,
		array $path,
	): string
	{
		foreach($path as $segment)
		{
			if(is_array($document) === false
				|| array_key_exists($segment, $document) === false)
			{
				return '';
			}
			$document = $document[$segment];
		}
		
		if(is_bool($document) === true)
		{
			return $document === true ? 'true' : 'false';
		}
		if(is_array($document) === true)
		{
			return (string)json_encode($document, JSON_UNESCAPED_UNICODE);
		}
		
		return (string)$document;
	}
	
	/**
	 * Keep-alive
	 */
	public function keepAlive(): Json
	{
		return new Json;
	}
}
