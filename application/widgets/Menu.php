<?php
declare(strict_types=1);

namespace Widgets;

use Ovos\Controller\Widget;
use Ovos\Exception;
use Ovos\Url;
use Ovos\View;
use Widgets\Menu\Item;

/**
 * Menu
 *
 * @package Widgets
 * @author Marcin Gil <mg@ovos.at>
 */
class Menu extends Widget
{
	/**
	 * @var string
	 */
	protected string $_url;

	/**
	 * @var Item[]
	 */
	protected array $_items = [];

	/**
	 */
	public function __construct(string $script = 'widgets/menu.phtml')
	{
		parent::__construct();
		
		$this->setScript($script);
		$this->setUrl($this->_app->getRequest()->getUrl());
	}

	/**
	 * @param Item $item
	 *
	 * @return self
	 */
	public function add(Item $item): self
	{
		$this->_items[] = $item;

		return $this;
	}

	/**
	 * @param string|Url $url
	 *
	 * @return self
	 */
	public function setUrl(string|Url $url): self
	{
		if($url instanceof Url)
		{
			$this->_url = $url->getUrl(true);

			return $this;
		}

		$this->_url = $url;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUrl(): string
	{
		return $this->_url;
	}

	/**
	 * @return string
	 *
	 * @throws Exception
	 */
	public function render(): string
	{
		foreach($this->_items as $item)
		{
			if(str_starts_with($this->_url, $item->getUrl()))
			{
				$item->setActive(true);
			}
		}

		$view = new View($this->_script);
		$view->items = $this->_items;

		return $view->__toString();
	}

	/**
	 * @return string
	 *
	 * @throws Exception
	 */
	public function __toString(): string
	{
		return $this->render();
	}

	/**
	 * @return array
	 */
	public function toArray(): array
	{
		return $this->_items;
	}
}
