<?php
declare(strict_types=1);

namespace Widgets;

use Ovos\Controller\Widget;
use Ovos\Url;
use Ovos\View;
use Override;
use Widgets\Menu\Item;

use function str_starts_with;

/**
 * Menu
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Menu extends Widget
{
	protected string $url;
	
	/**
	 * @var Item[]
	 */
	protected array $items = [];
	
	public function __construct(
		string $script = 'widgets/menu.phtml',
	)
	{
		parent::__construct();
		
		$this->setScript($script);
		$this->setUrl($this->app->getRequest()->getUrl());
	}
	
	public function add(
		Item $item,
	): self
	{
		$this->items[] = $item;
		
		return $this;
	}
	
	public function setUrl(
		string|Url $url,
	): self
	{
		if($url instanceof Url)
		{
			$this->url = $url->getUrl(true);
			
			return $this;
		}
		
		$this->url = $url;
		
		return $this;
	}
	
	public function getUrl(): string
	{
		return $this->url;
	}
	
	#[Override]
	public function render(): string
	{
		foreach($this->items as $item)
		{
			if(str_starts_with($this->url, $item->getUrl()))
			{
				$item->setActive(true);
			}
		}
		
		$view = new View($this->script);
		$view->items = $this->items;
		
		return $view->__toString();
	}
	
	#[Override]
	public function __toString(): string
	{
		return $this->render();
	}
	
	public function toArray(): array
	{
		return $this->items;
	}
}
