<?php
declare(strict_types=1);

namespace Controllers\System;

use Ovos\Controller;
use Ovos\Exception;
use Ovos\Exception\NotFoundException;
use Ovos\Exception\NotFoundException\FileNotFoundException;
use Ovos\Response;
use Ovos\View;
use function Ovos\services;

/**
 * Events
 *
 * @package Controllers
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
	 *
	 * @param ?string $output
	 *
	 * @return Response
	 */
	public function index(?string $output = null): Response
	{
		$view = new View('events.phtml');
		$events = services()->events->toArray();
		if($this->_app->getConfig()->system->debug)
		{
			$view->events = services()->events;
			$view->content = $output;
		}

		$response = $this->_app->getResponse(); // reuse the object
		// because of possible settings affecting output
		$response->setIsSent(false);
		
		// fetch last event
		$event = end($events);
		if($event instanceof NotFoundException)
		{
			$view->title = $this->_('Page not found.');
			$response->setHttpCode(404);
			
			if($event instanceof FileNotFoundException)
			{
				$view->title = $this->_('File not found.');
			}
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
