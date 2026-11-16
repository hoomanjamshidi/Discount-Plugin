<?php
/**
 * Smart resource management.
 *
 * The governor protects two things:
 *
 *  1. The batch request itself: it never runs near max_execution_time or memory_limit.
 *  2. The rest of the site (shoppers, admin): this is what the first version got wrong.
 *     A batch that keeps one PHP worker at 100% CPU for 20 seconds, back to back, is
 *     exactly what makes a shared host (CloudLinux LVE, LiteSpeed process limits, 1 vCPU
 *     VPS) answer visitors with 503. So the governor now
 *       - paces the work: after each product it rests in proportion to the time the
 *         product took (duty cycle: auto = 50% busy at most, gentle = 30%);
 *       - measures congestion itself instead of trusting the host's numbers: when a
 *         product suddenly takes several times longer than usual, or one product takes
 *         seconds, the server is struggling → smaller batches and longer rests;
 *       - uses the load average only as a hint, relative to what is normal for that
 *         host, and never as a reason to stop for good (it used to stall the job forever
 *         on hosts whose load is always "high" or whose CPU count is hidden);
 *       - budgets memory from the headroom that is actually left after WordPress and
 *         all other plugins loaded, instead of a fixed share of memory_limit (heavy sites
 *         already use 75% of 128M before our code runs — the old rule never started).
 *
 * Pure PHP (no WordPress calls) so it is fully unit tested.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Per-request resource budget + learned cross-request state.
 */
final class ResourceGovernor {

	const MIN_BATCH = 3;

	/** Never start (or continue) with less free memory than this. */
	const MIN_HEADROOM = 8388608; // 8 MB.

	/** Whole request (including any deliberate wait) stays under this share of max_execution_time. */
	const WALL_SHARE = 0.7;

	/** Longest single rest between two products (seconds). */
	const MAX_REST = 3.0;

	/** Highest pace multiplier (rest = work × (1-duty)/duty × pace). */
	const MAX_PACE = 8.0;

	/**
	 * Profile table.
	 *
	 * max_seconds   wall-clock length of one batch (work + rests)
	 * exec_share    batch never longer than this share of max_execution_time
	 * duty          share of wall time spent working (the rest is resting)
	 * base_cooldown seconds between batches when healthy
	 * mem_share     share of the free memory (after boot) one batch may use
	 * slow_item     one product taking longer than this (s) = server in trouble
	 * load_soft/hard load-per-core hints (relative to the host's normal load)
	 *
	 * @var array
	 */
	private static $profiles = array(
		'gentle' => array(
			'max_seconds'   => 5,
			'exec_share'    => 0.25,
			'duty'          => 0.3,
			'base_cooldown' => 4,
			'start_batch'   => 10,
			'max_batch'     => 40,
			'mem_share'     => 0.4,
			'slow_item'     => 2.5,
			'load_soft'     => 1.0,
			'load_hard'     => 2.0,
		),
		'auto'   => array(
			'max_seconds'   => 10,
			'exec_share'    => 0.4,
			'duty'          => 0.5,
			'base_cooldown' => 2,
			'start_batch'   => 20,
			'max_batch'     => 120,
			'mem_share'     => 0.6,
			'slow_item'     => 4.0,
			'load_soft'     => 1.2,
			'load_hard'     => 2.5,
		),
		'fast'   => array(
			'max_seconds'   => 25,
			'exec_share'    => 0.55,
			'duty'          => 0.85,
			'base_cooldown' => 0,
			'start_batch'   => 40,
			'max_batch'     => 400,
			'mem_share'     => 0.75,
			'slow_item'     => 8.0,
			'load_soft'     => 2.0,
			'load_hard'     => 4.0,
		),
	);

	/** @var Environment */
	private $env;
	/** @var array */
	private $p;
	/** @var string */
	private $profile;
	/** @var array */
	private $state;
	/** @var bool */
	private $use_load;
	/** @var float|null */
	private $max_seconds_override;

	/** @var float */
	private $started = 0.0;
	/** @var float */
	private $deadline = 0.0;
	/** @var bool */
	private $short_budget = false;
	/** @var int */
	private $items = 0;
	/** @var int */
	private $planned = 0;
	/** @var int */
	private $start_memory = 0;
	/** @var int */
	private $peak_memory = 0;
	/** @var int */
	private $last_memory = 0;
	/** @var int */
	private $max_growth = 0;
	/** @var int */
	private $memory_allowance = 0;
	/** @var float */
	private $item_mark = 0.0;
	/** @var float */
	private $last_item = 0.0;
	/** @var float */
	private $max_item = 0.0;
	/** @var float */
	private $work = 0.0;
	/** @var float */
	private $rested = 0.0;
	/** @var float */
	private $page_work = 0.0;
	/** @var bool */
	private $slow_hit = false;
	/** @var string */
	private $stop_reason = '';
	/** @var int */
	private $load_pressure = 0;
	/** @var string[] */
	private $stress = array();

	/**
	 * Constructor.
	 *
	 * @param Environment $env                  Readings.
	 * @param string      $profile              auto|gentle|fast.
	 * @param array       $state                Persisted learned state.
	 * @param bool        $use_load             Let the load average slow us down.
	 * @param float|null  $max_seconds_override Hard cap on this request (e.g. admin tick).
	 */
	public function __construct( Environment $env, $profile = 'auto', array $state = array(), $use_load = true, $max_seconds_override = null ) {
		$this->env                  = $env;
		$this->profile              = isset( self::$profiles[ $profile ] ) ? $profile : 'auto';
		$this->p                    = self::$profiles[ $this->profile ];
		if ( ! $env->can_pause() ) {
			// No sleep on this host: pacing inside a batch is impossible, so batches get
			// shorter and the whole rest happens between requests instead.
			$d                        = (float) $this->p['duty'];
			$this->p['max_seconds']   = max( 2, $this->p['max_seconds'] * $d );
			$this->p['base_cooldown'] = (int) ceil( max( $this->p['base_cooldown'], $this->p['max_seconds'] * ( 1 - $d ) / $d ) );
		}
		$this->use_load             = (bool) $use_load;
		$this->max_seconds_override = null === $max_seconds_override ? null : (float) $max_seconds_override;

		$defaults    = array(
			'batch_size'  => $this->p['start_batch'],
			'ms_per_item' => 0.0,
			'base_ms'     => 0.0,
			'pace'        => 1.0,
			'load_base'   => null,
			'history'     => array(),
			'last_run'    => 0,
			'total_items' => 0,
		);
		$this->state = array_merge( $defaults, array_intersect_key( $state, $defaults ) );

		// Persisted state may come from an older version or be corrupted: sanitise every field.
		$this->state['batch_size']  = $this->clamp_batch( (int) $this->state['batch_size'] );
		$this->state['ms_per_item'] = max( 0.0, (float) $this->state['ms_per_item'] );
		$this->state['base_ms']     = max( 0.0, (float) $this->state['base_ms'] );
		$this->state['pace']        = min( self::MAX_PACE, max( 1.0, (float) $this->state['pace'] ) );
		$this->state['load_base']   = is_numeric( $this->state['load_base'] ) ? max( 0.0, (float) $this->state['load_base'] ) : null;
		$this->state['history']     = is_array( $this->state['history'] ) ? array_values( $this->state['history'] ) : array();
		$this->state['total_items'] = max( 0, (int) $this->state['total_items'] );
	}

	/**
	 * Profile constants (for UI/tests).
	 *
	 * @return array
	 */
	public function profile() {
		return $this->p + array( 'name' => $this->profile );
	}

	// ------------------------------------------------------------------
	// Budgets.
	// ------------------------------------------------------------------

	/**
	 * Seconds this batch may last (wall clock), from "now".
	 *
	 * @return float
	 */
	public function time_budget() {
		$cap = (float) $this->p['max_seconds'];
		if ( null !== $this->max_seconds_override ) {
			$cap = min( $cap, $this->max_seconds_override );
		}
		$exec = $this->env->max_execution_time();
		if ( $exec > 0 ) {
			$used = max( 0.0, $this->env->now() - $this->env->request_start() );
			$cap  = min( $cap, $exec * $this->p['exec_share'], $exec * self::WALL_SHARE - $used );
		}
		return max( 0.0, $cap );
	}

	/**
	 * Budget of a fresh request (nothing used yet).
	 *
	 * @return float
	 */
	private function full_budget() {
		$cap = (float) $this->p['max_seconds'];
		if ( null !== $this->max_seconds_override ) {
			$cap = min( $cap, $this->max_seconds_override );
		}
		$exec = $this->env->max_execution_time();
		if ( $exec > 0 ) {
			$cap = min( $cap, $exec * $this->p['exec_share'], $exec * self::WALL_SHARE );
		}
		return max( 0.0, $cap );
	}

	/**
	 * Free memory before PHP's memory_limit (bytes).
	 *
	 * @return int
	 */
	public function headroom() {
		$limit = $this->env->memory_limit();
		if ( $limit <= 0 ) {
			return 512 * 1048576; // Unlimited: still behave as if 512M were left.
		}
		return max( 0, $limit - $this->env->memory_usage() );
	}

	/**
	 * Load per core, or null when unknown (hidden load or hidden CPU count).
	 *
	 * @return float|null
	 */
	public function load_ratio() {
		$load  = $this->env->load_average();
		$cores = $this->env->cpu_count();
		if ( null === $load || $cores <= 0 ) {
			return null;
		}
		return $load / $cores;
	}

	/**
	 * 0 = normal, 1 = busy, 2 = very busy — relative to what is normal on this host.
	 *
	 * @return int
	 */
	public function load_pressure() {
		if ( ! $this->use_load ) {
			return 0;
		}
		$ratio = $this->load_ratio();
		if ( null === $ratio ) {
			return 0;
		}
		$base = null === $this->state['load_base'] ? 0.0 : (float) $this->state['load_base'];
		$soft = max( (float) $this->p['load_soft'], $base * 1.6 );
		$hard = max( (float) $this->p['load_hard'], $base * 2.5 );
		if ( $ratio >= $hard ) {
			return 2;
		}
		return $ratio >= $soft ? 1 : 0;
	}

	/**
	 * May a batch start right now? Load never blocks (it only slows the work down);
	 * only a request that is nearly out of time or memory is refused.
	 *
	 * @return array{ok:bool,reason:string,wait:int}
	 */
	public function preflight() {
		if ( $this->time_budget() < 1.5 ) {
			return array(
				'ok'     => false,
				'reason' => 'request_time_low',
				'wait'   => 0,
			);
		}
		if ( $this->headroom() < self::MIN_HEADROOM + 4194304 ) {
			return array(
				'ok'     => false,
				'reason' => 'memory_high',
				'wait'   => 30,
			);
		}
		return array(
			'ok'     => true,
			'reason' => '',
			'wait'   => 0,
		);
	}

	// ------------------------------------------------------------------
	// One batch.
	// ------------------------------------------------------------------

	/**
	 * Start measuring a batch.
	 */
	public function begin() {
		$this->started          = $this->env->now();
		$budget                 = $this->time_budget();
		$this->deadline         = $this->started + $budget;
		// A request that already used most of its time (Action Scheduler ran other jobs
		// first) must not teach us that only a tiny batch fits.
		$this->short_budget     = $budget < 0.8 * $this->full_budget();
		$this->items            = 0;
		$this->start_memory     = $this->env->memory_usage();
		$this->peak_memory      = $this->start_memory;
		$this->last_memory      = $this->start_memory;
		$this->max_growth       = 0;
		$this->memory_allowance = (int) max( 0, ( $this->headroom() - self::MIN_HEADROOM ) * $this->p['mem_share'] );
		$this->item_mark        = $this->started;
		$this->last_item        = 0.0;
		$this->max_item         = 0.0;
		$this->work             = 0.0;
		$this->rested           = 0.0;
		$this->page_work        = 0.0;
		$this->slow_hit         = false;
		$this->stop_reason      = '';
		$this->stress           = array();
		$this->load_pressure    = $this->load_pressure();
		$this->learn_load();
		$this->planned = $this->batch_size();
		if ( 2 === $this->load_pressure ) {
			$this->planned = min( $this->planned, self::MIN_BATCH * 2 );
		}
	}

	/**
	 * Planned number of items for this batch.
	 *
	 * @return int
	 */
	public function batch_size() {
		$size = (int) $this->state['batch_size'];
		// If we already know the per-item cost, never plan more than ~80% of the working
		// part of the budget (the rest of the wall time is spent resting).
		$ms = (float) $this->state['ms_per_item'];
		if ( $ms > 0 ) {
			$work_ms = $this->time_budget() * 1000 * $this->duty();
			$fit     = (int) floor( ( $work_ms * 0.8 ) / $ms );
			$size    = min( $size, max( self::MIN_BATCH, $fit ) );
		}
		return $this->clamp_batch( $size );
	}

	/**
	 * Effective share of wall time spent working (lower when the host is struggling).
	 *
	 * @return float
	 */
	public function duty() {
		$d    = (float) $this->p['duty'];
		$rest = ( 1 - $d ) / $d * (float) $this->state['pace'];
		return 1 / ( 1 + $rest );
	}

	/**
	 * Count one unit of work (call right after it, before pace()).
	 *
	 * @param bool $product True for one product; false for a page of SQL work (listing or
	 *                      verifying), which counts for time, memory and pacing but is not
	 *                      used to learn the per-product cost.
	 */
	public function tick( $product = true ) {
		$now             = $this->env->now();
		$this->last_item = max( 0.0, $now - $this->item_mark );
		$this->max_item  = max( $this->max_item, $this->last_item );
		$this->item_mark = $now;
		if ( $product ) {
			$this->work += $this->last_item;
			$this->items++;
		} else {
			$this->page_work += $this->last_item;
		}

		$mem = $this->env->memory_usage();
		if ( $mem > $this->peak_memory ) {
			$this->peak_memory = $mem;
		}
		$this->max_growth  = max( $this->max_growth, $mem - $this->last_memory );
		$this->last_memory = $mem;

		if ( $this->last_item >= (float) $this->p['slow_item'] ) {
			$this->slow_hit = true;
		}
	}

	/**
	 * Must the batch stop now? Called after every item.
	 *
	 * @return bool
	 */
	public function should_yield() {
		if ( $this->slow_hit ) {
			$this->stop_reason = 'slow';
			return true;
		}
		if ( $this->env->now() >= $this->deadline ) {
			$this->stop_reason = 'time';
			return true;
		}
		$used = $this->env->memory_usage() - $this->start_memory;
		if ( $used >= $this->memory_allowance || $this->headroom() < self::MIN_HEADROOM + 2 * $this->max_growth ) {
			$this->stop_reason = 'memory';
			return true;
		}
		// Leave enough time for one more item of the slowest kind seen.
		if ( $this->items > 0 && $this->env->now() + $this->max_item > $this->deadline ) {
			$this->stop_reason = 'time';
			return true;
		}
		if ( $this->items >= $this->planned ) {
			$this->stop_reason = 'batch_done';
			return true;
		}
		return false;
	}

	/**
	 * Rest after an item, in proportion to how long it took (frees the CPU and the
	 * database for shoppers). Call after should_yield() returned false.
	 *
	 * @return float Seconds rested.
	 */
	public function pace() {
		$d    = (float) $this->p['duty'];
		$rest = $this->last_item * ( 1 - $d ) / $d * (float) $this->state['pace'];
		if ( 2 === $this->load_pressure ) {
			$rest *= 2;
		}
		$rest = min( $rest, self::MAX_REST, $this->deadline - $this->env->now() - $this->max_item );
		if ( $rest > 0.002 ) {
			$this->env->sleep( $rest );
			$this->rested += $rest;
		} else {
			$rest = 0.0;
		}
		$this->item_mark = $this->env->now();
		return $rest;
	}

	/**
	 * Why the batch stopped.
	 *
	 * @return string
	 */
	public function stop_reason() {
		return $this->stop_reason;
	}

	/**
	 * Finish a batch: learn from it.
	 *
	 * @return array Summary of the batch.
	 */
	public function finish() {
		$seconds = max( 0.0, $this->env->now() - $this->started );
		$size    = (int) $this->state['batch_size'];
		$ms      = 0.0;

		if ( $this->items > 0 ) {
			$work = $this->work > 0 ? $this->work : max( 0.0, $seconds - $this->page_work - $this->rested );
			$ms   = ( $work * 1000 ) / $this->items;
			$prev = (float) $this->state['ms_per_item'];
			$this->state['ms_per_item'] = $prev > 0 ? round( 0.7 * $prev + 0.3 * $ms, 2 ) : round( $ms, 2 );
		}

		// Congestion signals (any of them = the server is struggling).
		$stress = array();
		if ( 'memory' === $this->stop_reason ) {
			$stress[] = 'memory';
		}
		if ( 'slow' === $this->stop_reason ) {
			$stress[] = 'slow';
		}
		$base = (float) $this->state['base_ms'];
		if ( $this->items > 0 && $base > 0 && $ms > 3 * $base && $ms > 100 ) {
			$stress[] = 'latency';
		}
		$load_now = $this->load_pressure();
		if ( max( $load_now, $this->load_pressure ) > 0 ) {
			$stress[] = 'load';
		}
		$this->stress = $stress;

		// Learn the "normal" cost of one product on this host: follows improvements fast,
		// degradations slowly (so a congested period is recognised as congestion).
		if ( $this->items > 0 && empty( $stress ) ) {
			$base                     = $base <= 0 ? $ms : ( $ms < $base ? 0.5 * $base + 0.5 * $ms : 0.95 * $base + 0.05 * $ms );
			$this->state['base_ms'] = round( $base, 2 );
		} elseif ( $this->items > 0 && $base <= 0 ) {
			$this->state['base_ms'] = round( $ms, 2 );
		}

		$pace = (float) $this->state['pace'];
		if ( ! empty( $stress ) ) {
			$size = (int) floor( $size / 2 );
			$pace = min( self::MAX_PACE, $pace * ( 2 === max( $load_now, $this->load_pressure ) || in_array( 'slow', $stress, true ) ? 3 : 2 ) );
		} else {
			if ( 'time' === $this->stop_reason && $this->items > 0 && ! $this->short_budget ) {
				// What fit in the budget is the right size.
				$size = max( self::MIN_BATCH, (int) floor( $this->items * 0.9 ) );
			} elseif ( 'batch_done' === $this->stop_reason ) {
				$size = $size + max( 3, (int) floor( $size * 0.25 ) );
			}
			$pace = max( 1.0, $pace * 0.7 );
		}
		$this->state['batch_size']   = $this->clamp_batch( $size );
		$this->state['pace']         = round( $pace, 2 );
		$this->state['last_run']     = (int) $this->env->now();
		$this->state['total_items'] += $this->items;

		$ratio = $this->load_ratio();
		$entry = array(
			't'      => (int) $this->env->now(),
			'items'  => $this->items,
			'secs'   => round( $seconds, 2 ),
			'work'   => round( $this->work + $this->page_work, 2 ),
			'rest'   => round( $this->rested, 2 ),
			'ms'     => round( $ms, 1 ),
			'mem_mb' => round( $this->peak_memory / 1048576, 1 ),
			'load'   => null === $ratio ? null : round( $ratio, 2 ),
			'next'   => $this->state['batch_size'],
			'pace'   => $this->state['pace'],
			'why'    => $this->stop_reason,
			'stress' => implode( ',', $stress ),
		);
		$history                = (array) $this->state['history'];
		$history[]              = $entry;
		$this->state['history'] = array_slice( $history, -30 );

		return $entry;
	}

	/**
	 * Seconds to wait before the next batch.
	 *
	 * @return int
	 */
	public function cooldown() {
		$wait = (float) $this->p['base_cooldown'] * (float) $this->state['pace'];
		if ( ! empty( $this->stress ) ) {
			$wait = max( $wait, 5 * (float) $this->state['pace'] );
		}
		if ( in_array( 'memory', $this->stress, true ) ) {
			$wait = max( $wait, 10 );
		}
		if ( 2 === max( $this->load_pressure, $this->load_pressure() ) ) {
			$wait = max( $wait, 30 );
		}
		return (int) min( 60, ceil( $wait ) );
	}

	/**
	 * Congestion signals seen in the last finished batch.
	 *
	 * @return string[]
	 */
	public function stress() {
		return $this->stress;
	}

	/**
	 * State to persist.
	 *
	 * @return array
	 */
	public function state() {
		return $this->state;
	}

	/**
	 * Track what load is "normal" on this host (slow moving average).
	 */
	private function learn_load() {
		$ratio = $this->load_ratio();
		if ( null === $ratio ) {
			return;
		}
		$base                     = $this->state['load_base'];
		$this->state['load_base'] = round( null === $base ? $ratio : 0.9 * (float) $base + 0.1 * $ratio, 3 );
	}

	/**
	 * Clamp to profile bounds.
	 *
	 * @param int $size Size.
	 * @return int
	 */
	private function clamp_batch( $size ) {
		return max( self::MIN_BATCH, min( (int) $this->p['max_batch'], (int) $size ) );
	}
}
