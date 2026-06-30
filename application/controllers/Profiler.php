<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\ArrayObject;
use Ovos\Connection\RedisCommon;
use Ovos\Connections;
use Ovos\Controller;
use Ovos\Exception\NotFoundException;

use function connection_aborted;
use function flush;
use function header;
use function ignore_user_abort;
use function json_encode;
use function microtime;
use function ob_end_flush;
use function ob_get_level;
use function session_id;

/**
 * Profiler
 *
 * Server-sent events endpoint that tails the current session's profiler stream
 * (written by Ovos\Service\Profiler), feeding the live profiler panel and the
 * /profiler/ surface.
 *
 * Dev-only: 404 unless profilers.stream is enabled.
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Profiler extends Controller
{
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
	}
	
	/**
	 * SSE stream of the current session's profiler entries
	 */
	public function stream(): void
	{
		$sessionId = $this->getSessionId();
		
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
		
		$this->startStream();
		if($client === null)
		{
			return;
		}
		
		$key = (string)$this->stream->key_prefix . $sessionId;
		$lastId = $this->getLastId();
		$start = microtime(true);
		
		while(true)
		{
			if(connection_aborted()
				|| (microtime(true) - $start) * 1000 >= $maxLifetimeMs)
			{
				break;
			}
			
			$entries = $client->xRead([$key => $lastId], 1, $blockMs);
			if(empty($entries[$key]))
			{
				$this->send(': heartbeat');
				
				continue;
			}
			
			foreach($entries[$key] as $id => $fields)
			{
				$lastId = $id;
				$body = $fields['body'] ?? (string)json_encode($fields);
				$this->send('id: ' . $id . "\n" . 'data: ' . $body);
			}
		}
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
		$sessionId = session_id();
		$session->close();
		
		return $sessionId;
	}
	
	/**
	 * Resume after a reconnect, otherwise tail only new entries
	 */
	protected function getLastId(): string
	{
		return (string)($_SERVER['HTTP_LAST_EVENT_ID']
			?? $_GET['lastId']
			?? '$');
	}
	
	protected function startStream(): void
	{
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('Connection: keep-alive');
		header('X-Accel-Buffering: no'); // disable proxy buffering
		
		// the framework must not also render/append onto this stream
		$this->app->getResponse()->setIsSent(true);
		ignore_user_abort(false);
		
		$this->send('retry: 3000');
	}
	
	protected function send(
		string $message,
	): void
	{
		echo $message . "\n\n";
		
		while(ob_get_level() > 0)
		{
			ob_end_flush();
		}
		flush();
	}
}
