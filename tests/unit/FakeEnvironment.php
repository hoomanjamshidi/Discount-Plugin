<?php
/**
 * Controllable host readings for governor tests.
 *
 * @package SmartBulkDiscount\Tests
 */

// phpcs:ignoreFile

namespace SmartBulkDiscount\Tests;

use SmartBulkDiscount\Environment;

class FakeEnvironment extends Environment {
	public $now           = 1000.0;
	public $request_start = 1000.0;
	public $memory        = 40 * 1048576;
	public $memory_limit  = 128 * 1048576;
	public $max_exec      = 30;
	public $load          = null;
	public $cpus          = 4;
	public $slept         = 0.0;
	public $pausable      = true;

	public function now() { return $this->now; }
	public function request_start() { return $this->request_start; }
	public function memory_usage() { return $this->memory; }
	public function memory_limit() { return $this->memory_limit; }
	public function max_execution_time() { return $this->max_exec; }
	public function load_average() { return $this->load; }
	public function cpu_count() { return $this->cpus; }

	public function can_pause() { return $this->pausable; }
	public function sleep( $seconds ) { $this->slept += $seconds; $this->now += $seconds; }

	/** Advance the clock. */
	public function advance( $seconds ) { $this->now += $seconds; }
}
