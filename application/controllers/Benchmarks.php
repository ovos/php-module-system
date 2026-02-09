<?php
declare(strict_types=1);

namespace Controllers;

/**
 * Benchmarks
 *
 * @package Controllers
 * @author Marcin Gil <mg@ovos.at>
 */
class Benchmarks extends Tests
{
	/**
	 * @var string[]
	 */
	protected array $_configPath = ['system', 'benchmarks'];
	
	/**
	 * @var string
	 */
	protected string $_header = 'Benchmark';
	
	/**
	 * @var string
	 */
	protected string $_namespace = 'Benchmarks';
}
