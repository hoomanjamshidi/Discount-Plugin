<?php
// phpcs:ignoreFile

use SmartBulkDiscount\Environment;
use SmartBulkDiscount\ResourceGovernor as G;
use SmartBulkDiscount\Tests\FakeEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * The governor must keep a batch safe on ANY host and leave room for the shop's visitors.
 * Each "host" below is a profile seen in the wild.
 */
class ResourceGovernorTest extends TestCase {

	private function env() {
		return new FakeEnvironment();
	}

	/**
	 * Drive one batch the way Engine does: work → tick → should_yield → pace.
	 *
	 * @param FakeEnvironment $env     Env.
	 * @param G               $g       Governor.
	 * @param callable|float  $cost    Seconds per product (or fn($i) → seconds).
	 * @param int             $limit   Safety stop.
	 * @param int             $mem_inc Bytes leaked per product.
	 * @return int Products processed.
	 */
	private function run_batch( $env, $g, $cost, $limit = 1000, $mem_inc = 0 ) {
		$env->request_start = $env->now; // Each batch is its own request.
		$g->begin();
		$n = 0;
		while ( $n < $limit ) {
			$env->advance( is_callable( $cost ) ? $cost( $n ) : $cost );
			$env->memory += $mem_inc;
			$g->tick();
			$n++;
			if ( $g->should_yield() ) {
				break;
			}
			$g->pace();
		}
		return $n;
	}

	// ---------------------------------------------------------------- time.

	public function test_budget_respects_max_execution_time() {
		$env           = $this->env();
		$env->max_exec = 30;
		$this->assertEquals( 10.0, ( new G( $env, 'auto' ) )->time_budget() );
		$env->max_exec = 15; // 40% of 15.
		$this->assertEquals( 6.0, ( new G( $env, 'auto' ) )->time_budget() );
	}

	public function test_budget_subtracts_time_already_used_by_request() {
		$env           = $this->env();
		$env->max_exec = 30;
		$env->now      = $env->request_start + 15; // e.g. a loopback request that waited, or AS ran other jobs.
		// Wall cap: 70% of 30 = 21 → 6s left.
		$this->assertEquals( 6.0, ( new G( $env, 'auto' ) )->time_budget() );
	}

	public function test_budget_capped_by_profile_when_unlimited() {
		$env           = $this->env();
		$env->max_exec = 0;
		$this->assertEquals( 10.0, ( new G( $env, 'auto' ) )->time_budget() );
		$this->assertEquals( 5.0, ( new G( $env, 'gentle' ) )->time_budget() );
		$this->assertEquals( 25.0, ( new G( $env, 'fast' ) )->time_budget() );
		$this->assertEquals( 8.0, ( new G( $env, 'auto', array(), true, 8 ) )->time_budget() );
	}

	public function test_preflight_refuses_when_request_nearly_out_of_time() {
		$env           = $this->env();
		$env->max_exec = 30;
		$env->now      = $env->request_start + 20; // 21 - 20 = 1s left.
		$pre           = ( new G( $env, 'auto' ) )->preflight();
		$this->assertFalse( $pre['ok'] );
		$this->assertSame( 'request_time_low', $pre['reason'] );
	}

	public function test_batch_never_reaches_max_execution_time_even_with_slow_products() {
		foreach ( array( 'gentle', 'auto', 'fast' ) as $profile ) {
			foreach ( array( 0.02, 0.4, 1.9, 3.5 ) as $cost ) {
				$env           = $this->env();
				$env->max_exec = 30;
				$g             = new G( $env, $profile, array( 'batch_size' => 400 ) );
				$this->run_batch( $env, $g, $cost );
				$elapsed = $env->now - $env->request_start;
				$this->assertLessThan( 30 * 0.75, $elapsed, "$profile @ {$cost}s/product ran {$elapsed}s" );
			}
		}
	}

	// ---------------------------------------------------------------- site protection.

	public function test_pacing_leaves_the_server_free_half_of_the_time() {
		$env           = $this->env();
		$env->max_exec = 0;
		$g             = new G( $env, 'auto', array( 'batch_size' => 1000 ) );
		$n             = $this->run_batch( $env, $g, 0.1 );
		$s             = $g->finish();
		$this->assertGreaterThan( 10, $n );
		$busy = $s['work'] / $s['secs'];
		$this->assertLessThanOrEqual( 0.55, $busy, 'auto works at most ~50% of the wall time' );
		$this->assertGreaterThan( 0.3, $busy );
	}

	public function test_host_without_sleep_gets_short_batches_and_long_rests() {
		$env           = $this->env();
		$env->max_exec = 0;
		$env->pausable = false;
		$g             = new G( $env, 'auto' );
		$this->assertEquals( 5.0, $g->time_budget() );
		$this->assertGreaterThanOrEqual( 5, $g->cooldown() );
	}

	public function test_gentle_profile_rests_more() {
		$env           = $this->env();
		$env->max_exec = 0;
		$g             = new G( $env, 'gentle', array( 'batch_size' => 1000 ) );
		$this->run_batch( $env, $g, 0.1 );
		$s = $g->finish();
		$this->assertLessThanOrEqual( 0.35, $s['work'] / $s['secs'] );
	}

	public function test_congestion_is_detected_from_product_latency_without_load_average() {
		// Shared host (LVE): load average and CPU count hidden. Products normally take 50ms;
		// then the host gets throttled and they take 400ms.
		$env           = $this->env();
		$env->load     = null;
		$env->cpus     = 0;
		$env->max_exec = 30;
		$state         = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$g = new G( $env, 'auto', $state );
			$this->run_batch( $env, $g, 0.05 );
			$g->finish();
			$state = $g->state();
			$env->advance( $g->cooldown() );
		}
		$healthy_size = $state['batch_size'];
		$this->assertEqualsWithDelta( 50, $state['base_ms'], 5 );
		$this->assertSame( 1.0, (float) $state['pace'] );

		$g = new G( $env, 'auto', $state );
		$this->run_batch( $env, $g, 0.4 );
		$s = $g->finish();
		$this->assertStringContainsString( 'latency', $s['stress'] );
		$this->assertLessThan( $healthy_size, $s['next'], 'batch shrinks' );
		$this->assertGreaterThan( 1.0, $g->state()['pace'], 'rests get longer' );
		$this->assertGreaterThanOrEqual( 5, $g->cooldown(), 'longer pause between batches' );
		$this->assertEqualsWithDelta( 50, $g->state()['base_ms'], 5, 'congestion is not learned as normal' );
	}

	public function test_one_very_slow_product_stops_the_batch_at_once() {
		$env = $this->env();
		$g   = new G( $env, 'auto', array( 'batch_size' => 100 ) );
		$n   = $this->run_batch(
			$env,
			$g,
			function ( $i ) {
				return 3 === $i ? 5.0 : 0.05; // A plugin hook on save hangs for 5s.
			}
		);
		$this->assertSame( 4, $n );
		$this->assertSame( 'slow', $g->stop_reason() );
		$g->finish();
		$this->assertContains( 'slow', $g->stress() );
	}

	public function test_pace_recovers_when_the_host_is_healthy_again() {
		$env   = $this->env();
		$state = array( 'pace' => 8, 'base_ms' => 50, 'batch_size' => 10 );
		for ( $i = 0; $i < 10; $i++ ) {
			$g = new G( $env, 'auto', $state );
			$this->run_batch( $env, $g, 0.05 );
			$g->finish();
			$state = $g->state();
		}
		$this->assertSame( 1.0, (float) $state['pace'] );
		$this->assertGreaterThan( 10, $state['batch_size'] );
	}

	// ---------------------------------------------------------------- load average.

	public function test_high_load_never_blocks_the_job() {
		// The old engine refused to start whenever load/cores ≥ 1.5 — on hosts with a
		// permanently busy machine, or a hidden CPU count (treated as 1), it never ran.
		$env       = $this->env();
		$env->load = 40.0;
		$env->cpus = 4;
		$pre       = ( new G( $env, 'auto' ) )->preflight();
		$this->assertTrue( $pre['ok'] );
		$g = new G( $env, 'auto', array( 'batch_size' => 50 ) );
		$n = $this->run_batch( $env, $g, 0.05 );
		$this->assertGreaterThanOrEqual( 1, $n, 'makes progress' );
		$this->assertLessThanOrEqual( G::MIN_BATCH * 2, $n, 'but only a little at a time' );
	}

	public function test_unknown_cpu_count_ignores_load() {
		$env       = $this->env();
		$env->load = 12.0;
		$env->cpus = 0; // /proc hidden.
		$g         = new G( $env, 'auto' );
		$this->assertNull( $g->load_ratio() );
		$this->assertSame( 0, $g->load_pressure() );
	}

	public function test_load_is_judged_against_what_is_normal_for_this_host() {
		// A shared machine whose load is always ~2 per core.
		$env       = $this->env();
		$env->cpus = 8;
		$env->load = 16.0;
		$state     = array( 'load_base' => 2.0 );
		$g         = new G( $env, 'auto', $state );
		$this->assertSame( 0, $g->load_pressure(), 'its usual load is not "busy"' );
		$env->load = 44.0; // 5.5 per core: far above its normal.
		$this->assertSame( 2, ( new G( $env, 'auto', $state ) )->load_pressure() );
	}

	public function test_load_hint_can_be_switched_off() {
		$env       = $this->env();
		$env->load = 40.0;
		$this->assertSame( 0, ( new G( $env, 'auto', array(), false ) )->load_pressure() );
	}

	// ---------------------------------------------------------------- memory.

	public function test_heavy_site_with_little_headroom_still_runs() {
		// WordPress + a page builder + 40 plugins already use 100M of 128M before we start.
		// The old rule (stop at 75% of memory_limit) never started a single batch.
		$env         = $this->env();
		$env->memory = 100 * 1048576;
		$g           = new G( $env, 'auto', array( 'batch_size' => 120 ) );
		$this->assertTrue( $g->preflight()['ok'] );
		$n = $this->run_batch( $env, $g, 0.05, 1000, 512 * 1024 );
		$this->assertGreaterThan( 5, $n );
		$this->assertSame( 'memory', $g->stop_reason() );
		$this->assertLessThan( 128 * 1048576 - G::MIN_HEADROOM, $env->memory, 'never near the limit' );
	}

	public function test_no_memory_left_is_refused() {
		$env         = $this->env();
		$env->memory = 120 * 1048576;
		$pre         = ( new G( $env, 'auto' ) )->preflight();
		$this->assertFalse( $pre['ok'] );
		$this->assertSame( 'memory_high', $pre['reason'] );
	}

	public function test_memory_leak_halves_the_batch() {
		$env = $this->env();
		$g   = new G( $env, 'auto', array( 'batch_size' => 100 ) );
		$this->run_batch( $env, $g, 0.02, 1000, 2 * 1048576 );
		$this->assertSame( 'memory', $g->stop_reason() );
		$this->assertLessThan( 128 * 1048576, $env->memory );
		$this->assertSame( 50, $g->finish()['next'] );
		$this->assertGreaterThanOrEqual( 10, $g->cooldown() );
	}

	public function test_unlimited_memory() {
		$env               = $this->env();
		$env->memory_limit = 0;
		$this->assertSame( 512 * 1048576, ( new G( $env, 'auto' ) )->headroom() );
	}

	// ---------------------------------------------------------------- learning.

	public function test_batch_grows_when_healthy() {
		$env = $this->env();
		$g   = new G( $env, 'auto', array( 'batch_size' => 20 ) );
		$this->run_batch( $env, $g, 0.05 );
		$this->assertSame( 'batch_done', $g->stop_reason() );
		$this->assertSame( 25, $g->finish()['next'] );
	}

	public function test_planned_batch_respects_known_cost() {
		$env           = $this->env();
		$env->max_exec = 30; // 10s budget, 50% duty → 5s of work.
		$g             = new G( $env, 'auto', array( 'batch_size' => 120, 'ms_per_item' => 500 ) );
		// 5s * 0.8 / 0.5s = 8.
		$this->assertSame( 8, $g->batch_size() );
	}

	public function test_page_ticks_do_not_distort_the_product_cost() {
		$env = $this->env();
		$g   = new G( $env, 'auto', array( 'batch_size' => 100 ) );
		$g->begin();
		for ( $i = 0; $i < 3; $i++ ) { // three SQL pages of 0.3s
			$env->advance( 0.3 );
			$g->tick( false );
			$g->pace();
		}
		for ( $i = 0; $i < 10; $i++ ) {
			$env->advance( 0.05 );
			$g->tick();
			$g->pace();
		}
		$s = $g->finish();
		$this->assertSame( 10, $s['items'] );
		$this->assertEqualsWithDelta( 50, $s['ms'], 1 );
	}

	public function test_a_request_with_little_time_left_does_not_shrink_the_batch() {
		$env           = $this->env();
		$env->max_exec = 30;
		$g             = new G( $env, 'auto', array( 'batch_size' => 60 ) );
		$env->now      = $env->request_start + 18; // 3s left of the 21s wall cap.
		$g->begin();
		while ( ! $g->should_yield() ) {
			$env->advance( 0.1 );
			$g->tick();
			$g->pace();
		}
		$this->assertSame( 'time', $g->stop_reason() );
		$this->assertSame( 60, $g->finish()['next'] );
	}

	public function test_time_stop_with_only_pages_keeps_batch_size() {
		$env = $this->env();
		$g   = new G( $env, 'auto', array( 'batch_size' => 60 ) );
		$g->begin();
		while ( ! $g->should_yield() ) {
			$env->advance( 0.5 );
			$g->tick( false );
			$g->pace();
		}
		$this->assertSame( 60, $g->finish()['next'] );
	}

	public function test_corrupted_state_is_sanitised() {
		$env = $this->env();
		$g   = new G( $env, 'auto', array( 'batch_size' => 'x', 'pace' => 99, 'history' => 'oops', 'load_base' => 'n/a', 'ms_per_item' => -4 ) );
		$st  = $g->state();
		$this->assertSame( G::MIN_BATCH, $st['batch_size'] );
		$this->assertSame( G::MAX_PACE, $st['pace'] );
		$this->assertSame( array(), $st['history'] );
		$this->assertNull( $st['load_base'] );
		$this->assertSame( 0.0, $st['ms_per_item'] );
	}

	public function test_batch_bounds() {
		$env = $this->env();
		$this->assertSame( G::MIN_BATCH, ( new G( $env, 'auto', array( 'batch_size' => 1 ) ) )->batch_size() );
		$this->assertSame( 40, ( new G( $env, 'gentle', array( 'batch_size' => 1000 ) ) )->batch_size() );
	}

	public function test_cooldown_by_profile() {
		$env = $this->env();
		$this->assertSame( 2, ( new G( $env, 'auto' ) )->cooldown() );
		$this->assertSame( 4, ( new G( $env, 'gentle' ) )->cooldown() );
		$this->assertSame( 0, ( new G( $env, 'fast' ) )->cooldown() );
		$this->assertLessThanOrEqual( 60, ( new G( $env, 'gentle', array( 'pace' => 8 ) ) )->cooldown() );
	}

	public function test_history_is_capped() {
		$env   = $this->env();
		$state = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$g = new G( $env, 'auto', $state );
			$g->begin();
			$env->advance( 0.01 );
			$g->tick();
			$g->finish();
			$state = $g->state();
		}
		$this->assertCount( 30, $state['history'] );
		$this->assertSame( 40, $state['total_items'] );
	}

	public function test_to_bytes() {
		$this->assertSame( 128 * 1048576, Environment::to_bytes( '128M' ) );
		$this->assertSame( 1073741824, Environment::to_bytes( '1G' ) );
		$this->assertSame( 512 * 1024, Environment::to_bytes( '512k' ) );
		$this->assertSame( 1000, Environment::to_bytes( '1000' ) );
	}
}
