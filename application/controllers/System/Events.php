<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Exception\ForbiddenException;
use Ovos\Exception\NotFoundException;
use Ovos\Exception\NotFoundException\PageNotFoundException;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Response;
use Ovos\Service\Events as ServiceEvents;
use Ovos\View;

use function end;
use function ob_clean;
use function ob_get_length;

/**
 * Events
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Events extends Controller
{
	/**
	 */
	public function __construct()
	{
		parent::__construct();
		
		// these actions should be available to unauthorized users
		if($auth = $this->auth())
		{
			$auth->authorizeActions(['index']);
		}
	}
	
	/**
	 * Index
	 */
	public function index(
		?string $output = null,
	): Response
	{
		$view = new View('events.phtml');
		$eventsService = $this->container
			->get(ServiceEvents::SYMBOL);
		
		if($this->app->getConfig()->system->debug)
		{
			$view->events = $eventsService;
			$view->content = $output;
		}
		
		$response = $this->app->getResponse(); // reuse the object
		// because of possible settings affecting output
		$response->setIsSent(false);
		
		$events = $eventsService
			->toArray();
		// fetch last event
		$event = end($events);
		if($event instanceof NotFoundException)
		{
			$view->title = $this->_('Not found.');
			$response->setHttpCode(404);
			
			if($event instanceof PageNotFoundException)
			{
				$view->title = $this->_('Page not found.');
			}
			if($event instanceof FileNotFoundException)
			{
				$view->title = $this->_('File not found.');
			}
		}
		else if($event instanceof ForbiddenException)
		{
			$view->title = $this->_('Forbidden.');
			$response->setHttpCode(403);
		}
		else
		{
			$response->setHttpCode(500);
		}
		
		if(ob_get_length())
		{
			ob_clean(); // clean nested view outputs
		}
		
		return $response->set($view->render());
	}
}
