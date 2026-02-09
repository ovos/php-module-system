<?php
declare(strict_types=1);

namespace Widgets\Menu;

use Ovos\Url;

/**
 * Item
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Item
{
	protected string $label;
	
	protected ?string $title = null;
	
	protected ?Url $url = null;
	
	protected bool $active = false;
	
	public function __construct(
		string $label,
		null|string|Url $url = null,
		?string $title = null,
	)
	{
		$this->setLabel($label);
		$this->setUrl($url);
		$this->setTitle($title);
	}
	
	public function setLabel(
		?string $label,
	): self
	{
		$this->label = $label;
		
		return $this;
	}
	
	public function getLabel(): string
	{
		return $this->label;
	}
	
	public function setTitle(
		?string $title,
	): self
	{
		$this->title = $title;
		
		return $this;
	}
	
	public function getTitle(): string
	{
		return $this->title;
	}
	
	public function setUrl(
		null|string|Url $url,
	): self
	{
		if($url instanceof Url)
		{
			$this->url = $url->setRelative(true);
			
			return $this;
		}
		
		$this->url = new Url($url);
		$this->url->setRelative(true);
		
		return $this;
	}
	
	public function getUrl(): string
	{
		return $this->url->__toString();
	}
	
	public function setActive(
		bool $active,
	): self
	{
		$this->active = $active;
		
		return $this;
	}
	
	public function isActive(): bool
	{
		return $this->active;
	}
}
