<?php
declare(strict_types=1);

namespace Controllers;

/**
 * Benchmarks
 *
 * @author Marcin Gil <mg@ovos.at>
 */
class Benchmarks extends Tests
{
	/**
	 * @var string[]
	 */
	protected array $configPath = ['system', 'benchmarks'];
	
	protected string $header = 'Benchmark';
	
	protected string $namespace = 'Benchmarks';
}
