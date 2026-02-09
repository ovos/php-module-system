<?php
declare(strict_types=1);

namespace Widgets\Menu;

use Ovos\Url;

/**
 * Item
 *
 * @package Widgets
 * @author Marcin Gil <mg@ovos.at>
 */
class Item
{
	/**
	 * @var string
	 */
	protected string $_label;

	/**
	 * @var ?string
	 */
	protected ?string $_title = null;

	/**
	 * @var ?Url
	 */
	protected ?Url $_url = null;

	/**
	 * @var bool
	 */
	protected bool $_active = false;

	/**
	 * @param string $label
	 * @param null|string|Url $url
	 * @param ?string $title
	 */
	public function __construct(
		string $label,
		null|string|Url $url = null,
		?string $title = null
	)
	{
		$this->setLabel($label);
		$this->setUrl($url);
		$this->setTitle($title);
	}

	/**
	 * @param ?string $label
	 *
	 * @return self
	 */
	public function setLabel(?string $label): self
	{
		$this->_label = $label;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLabel(): string
	{
		return $this->_label;
	}

	/**
	 * @param ?string $title
	 *
	 * @return self
	 */
	public function setTitle(?string $title): self
	{
		$this->_title = $title;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getTitle(): string
	{
		return $this->_title;
	}

	/**
	 * @param null|string|Url $url
	 *
	 * @return self
	 */
	public function setUrl(null|string|Url $url): self
	{
		if($url instanceof Url)
		{
			$this->_url = $url->setRelative(true);
			
			return $this;
		}
		
		$this->_url = new Url($url);
		$this->_url->setRelative(true);

		return $this;
	}

	/**
	 * @return string
	 */
	public function getUrl(): string
	{
		return $this->_url->__toString();
	}

	/**
	 * @param bool $active
	 *
	 * @return self
	 */
	public function setActive(bool $active): self
	{
		$this->_active = $active;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isActive(): bool
	{
		return $this->_active;
	}
}
