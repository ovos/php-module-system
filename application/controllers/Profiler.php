<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\ArrayObject;
use Ovos\Client;
use Ovos\Connection\RedisCommon;
use Ovos\Connections;
use Ovos\Controller;
use Ovos\Exception\NotFoundException;
use Ovos\Response\Json;
use Ovos\Service\Profiler as ProfilerService;
use Ovos\View;
use Redis as RedisClient;

use function array_filter;
use function array_map;
use function array_reverse;
use function connection_aborted;
use function explode;
use function flush;
use function ignore_user_abort;
use function in_array;
use function is_file;
use function is_string;
use function json_encode;
use function method_exists;
use function microtime;
use function preg_match;
use function readfile;
use function set_time_limit;
use function trim;

/**
 * Profiler
 *
 * The /profiler/ surface: an HTML page (index) and a server-sent events
 * endpoint (stream) that tails a profiler stream written by
 * Ovos\Service\Profiler — the current session's requests by default, the
 * shared CLI stream with ?scope=cli. Feeds the live panel and the full page.
 *
 * 404 unless profilers.stream is enabled; behind an admin gate where the
 * project's auth plugin can answer isAdmin(), an optional IP allowlist
 * otherwise (see gate()).
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Profiler extends Controller
{
	/**
	 * Entries fetched per XREAD — a burst of profiled requests drains in one
	 * round trip instead of one blocking read per entry
	 */
	public const int READ_BATCH = 50;
	
	protected ArrayObject $stream;
	
	public function __construct()
	{
		parent::__construct();
		
		$profilers = $this->app->getConfig()->system->profilers;
		if($profilers?->enabled !== true
			|| $profilers->stream?->enabled !== true)
		{
			throw new NotFoundException('Profiler streaming is not enabled.');
		}
		
		$this->stream = $profilers->stream;
		
		$this->gate();
	}
	
	/**
	 * Access gate. A project whose auth plugin can answer isAdmin() (bo2go
	 * convention) gets an admin-only profiler — in development the console's
	 * auth service is disabled and reports every visitor as admin, so the
	 * panel keeps working everywhere, login page included. Projects without
	 * such a plugin fall back to an optional IP allowlist
	 * (profilers.stream.ips — comma-separated, env-driven); an empty list
	 * keeps the surface open. 404 either way — the profiler does not
	 * advertise itself.
	 */
	protected function gate(): void
	{
		$auth = $this->auth();
		
		if($auth !== null && method_exists($auth, 'isAdmin'))
		{
			if($auth->isAdmin() === false)
			{
				throw new NotFoundException('Profiler requires an administrator.');
			}
		}
		else
		{
			$allowed = array_filter(array_map(
				trim(...),
				explode(',', (string)($this->stream->ips ?? '')),
			));
			
			if($allowed !== []
				&& in_array(Client::getIp(), $allowed, true) === false)
			{
				throw new NotFoundException('Profiler is not open to this address.');
			}
		}
		
		// passed: the panel loads its script and tails the stream on every
		// page - including the login page itself - so these actions must
		// skip the auth plugin's own pre-dispatch (mirrors System\Events)
		$auth?->authorizeActions(['index', 'stream', 'clear', 'asset']);
	}
	
	/**
	 * The stream key for the requested scope: the caller's session stream,
	 * or — with ?scope=cli — the shared CLI stream every run lands on
	 */
	protected function getStreamKey(): string
	{
		if($this->isCliScope())
		{
			return (string)$this->stream->key_prefix . ProfilerService::CLI_KEY;
		}

		return (string)$this->stream->key_prefix . $this->getSessionId();
	}

	protected function isCliScope(): bool
	{
		return ($_GET['scope'] ?? null) === 'cli';
	}
	
	/**
	 * Full profiler surface — every retained request for this session
	 */
	public function index(): void
	{
		$view = new View('layouts/profiler.phtml');
		$view->streamUrl = SYSTEM_PATH . 'profiler/stream';
		$view->clearUrl = SYSTEM_PATH . 'profiler/clear';
		
		// render our own standalone page — bypass the app layout (page.phtml),
		// which would otherwise nest this document and double-load profiler.js
		$this->app->getResponse()
			->setHeader('Content-Type', 'text/html; charset=utf-8', true)
			->sendHeaders()
			->setIsSent(true);
		echo $view->render();
	}
	
	/**
	 * Drops the current session's profiler stream, clearing every retained
	 * record. POST only (a state change); the live tail keeps working — the
	 * next request re-creates the stream.
	 */
	public function clear(): Json
	{
		$response = new Json;
		
		if($this->request->isPost() === false)
		{
			return $response->failure('Clearing the profiler requires POST.', true);
		}
		
		$connection = $this->container
			->getClass(Connections::class)
			->get((string)$this->stream->connection);
		$client = $connection->getClient();
		if($client === null)
		{
			return $response->failure('Profiler connection unavailable.', true);
		}
		
		$response->cleared = (int)$client->del($this->getStreamKey());
		
		return $response;
	}
	
	/**
	 * Serves the profiler client script, shipped with this module so the
	 * profiler is self-contained (no per-project public asset required)
	 */
	public function asset(): void
	{
		$file = __DIR__ . '/../assets/profiler.js';
		
		$this->app->getResponse()
			->setHeader('Content-Type', 'text/javascript; charset=utf-8', true)
			->setHeader('Cache-Control', 'no-cache', true)
			->sendHeaders()
			->setIsSent(true);
		
		if(is_file($file))
		{
			readfile($file);
		}
	}
	
	/**
	 * SSE stream of the requested scope's profiler entries
	 */
	public function stream(): void
	{
		$key = $this->getStreamKey();
		
		$blockMs = (int)$this->stream->block_ms;
		$maxLifetimeMs = (int)$this->stream->max_lifetime_ms;
		
		$connection = $this->container
			->getClass(Connections::class)
			->get((string)$this->stream->connection);
		// a blocking xRead must outlive the connection's light read timeout
		$connection->toggleReadTimeout(
			RedisCommon::TIMEOUT_READ_CUSTOM,
			($blockMs / 1000) + 5,
			false, // skip the lua busy-reply config (may lack CONFIG perms)
		);
		$client = $connection->getClient();
		if($client === null)
		{
			// fail BEFORE the SSE preamble: 'retry: 3000' on a dead redis
			// would put the browser into an endless silent reconnect loop,
			// while a non-200 makes EventSource surface the error and stop
			$this->app->getResponse()
				->setHttpCode(503)
				->setHeader('Content-Type', 'text/plain; charset=utf-8', true)
				->sendHeaders()
				->setIsSent(true);
			echo 'profiler stream unavailable';
			
			return;
		}
		
		$this->startStream();
		
		$lastId = $this->getLastId();
		// a fresh connect (no resume id) replays recent history first and
		// yields a concrete anchor — never '$', which XREAD re-anchors to
		// "now" on every retry, skipping entries written between two reads
		if($lastId === '$')
		{
			$lastId = $this->backfill($client, $key);
		}
		$start = microtime(true);
		
		while(true)
		{
			if(connection_aborted()
				|| (microtime(true) - $start) * 1000 >= $maxLifetimeMs)
			{
				break;
			}
			
			$entries = $client->xRead([$key => $lastId], self::READ_BATCH, $blockMs);
			if(empty($entries[$key]))
			{
				$this->send(': heartbeat');
				
				continue;
			}
			
			foreach($entries[$key] as $id => $fields)
			{
				$lastId = $id;
				$this->sendEntry($id, $fields);
			}
		}
	}
	
	/**
	 * The concrete id of the newest entry, or '0-0' for a missing/empty
	 * stream — both anchors deliver everything written after this moment
	 */
	protected function lastStreamId(
		RedisClient $client,
		string $key,
	): string
	{
		$entries = $client->xRevRange($key, '+', '-', 1);
		if(empty($entries))
		{
			return '0-0';
		}
		
		foreach($entries as $id => $fields)
		{
			return (string)$id;
		}
		
		return '0-0';
	}
	
	/**
	 * Replays the last N entries on a fresh connect and returns the concrete
	 * id to continue tailing from. An empty backfill anchors at 0-0 — taking
	 * a second "what is the tail now" snapshot instead would lose whatever
	 * landed between the two reads (XREAD only returns ids strictly greater
	 * than the anchor).
	 */
	protected function backfill(
		RedisClient $client,
		string $key,
	): string
	{
		// the CLI stream is message-grained — the same replay depth would
		// cover barely a run or two, so it gets its own (deeper) setting
		$count = $this->isCliScope()
			? (int)($this->stream->cli_backfill ?? $this->stream->backfill)
			: (int)$this->stream->backfill;
		if($count <= 0)
		{
			// replay disabled: tail from the current end of the stream
			return $this->lastStreamId($client, $key);
		}
		
		$entries = $client->xRevRange($key, '+', '-', $count);
		if(empty($entries))
		{
			// nothing to replay — deliver everything that ever lands
			return '0-0';
		}
		
		$lastId = '0-0';
		foreach(array_reverse($entries, true) as $id => $fields)
		{
			$this->sendEntry($id, $fields);
			$lastId = $id;
		}
		
		return $lastId;
	}
	
	/**
	 * Reads the session id, then releases the lock immediately — an open
	 * session would serialize (block) every other request in the same session
	 * for the whole lifetime of the stream
	 */
	protected function getSessionId(): string
	{
		$session = $this->app->getServices()->session;
		$session->start();
		// handler-agnostic: session_id() is EMPTY under the json handler,
		// whose ids never touch the native machinery
		$sessionId = (string)$session->getId();
		$session->close();
		
		return $sessionId;
	}
	
	/**
	 * Resume after a reconnect, otherwise tail only new entries
	 */
	protected function getLastId(): string
	{
		$lastId = $_SERVER['HTTP_LAST_EVENT_ID']
			?? $_GET['lastId']
			?? '$';
		
		// query params can arrive as arrays (?lastId[]=x — a string cast
		// would throw via the promoted warning) and a stream id must look
		// like <ms>-<seq>; anything else falls back to a fresh tail
		if(is_string($lastId) === false
			|| preg_match('/^\d+-\d+$/', $lastId) !== 1)
		{
			return '$';
		}
		
		return $lastId;
	}
	
	protected function startStream(): void
	{
		// flush any open output buffers so events reach the browser immediately
		$this->app->getResponse()
			->flushBuffers()
			->setHeader('Content-Type', 'text/event-stream', true)
			->setHeader('Cache-Control', 'no-cache, no-store', true)
			->setHeader('Connection', 'keep-alive', true)
			->setHeader('Content-Encoding', 'none', true) // stop gzip from buffering the stream
			->setHeader('X-Accel-Buffering', 'no', true) // disable nginx proxy buffering
			->sendHeaders()
			// the framework must not also render/append onto this stream
			->setIsSent(true);
		
		// the loop outlives PHP's max_execution_time (30s under Apache) — lift it;
		// the loop is still bounded by max_lifetime_ms and connection_aborted()
		set_time_limit(0);
		ignore_user_abort(false);
		
		$this->send('retry: 3000');
	}
	
	/**
	 * @param array<string, string> $fields
	 */
	protected function sendEntry(
		string $id,
		array $fields,
	): void
	{
		$body = $fields['body'] ?? (string)json_encode($fields);
		$this->send('id: ' . $id . "\n" . 'data: ' . $body);
	}
	
	protected function send(
		string $message,
	): void
	{
		echo $message . "\n\n";
		flush();
	}
}
