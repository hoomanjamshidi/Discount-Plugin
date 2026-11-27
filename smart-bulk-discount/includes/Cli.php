<?php
/**
 * WP-CLI: `wp dsd ...` — manual run path for big catalogs and debugging.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Smart Bulk Discount campaigns.
 */
final class Cli {

	/** @var CampaignService */
	private $service;
	/** @var Repository */
	private $repo;
	/** @var Engine */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param CampaignService $service Service.
	 * @param Repository      $repo    Repo.
	 * @param Engine          $engine  Engine.
	 */
	public function __construct( CampaignService $service, Repository $repo, Engine $engine ) {
		$this->service = $service;
		$this->repo    = $repo;
		$this->engine  = $engine;
	}

	/**
	 * List campaigns.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table|json|csv. Default table.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( $this->repo->all() as $c ) {
			$s      = array_merge( Repository::empty_stats(), $this->repo->counts( $c->id ) );
			$rows[] = array(
				'id'       => $c->id,
				'name'     => $c->name,
				'status'   => $c->status,
				'discount' => $c->discount_value . ( 'percent' === $c->discount_type ? '%' : '' ),
				'target'   => $c->target_type,
				'total'    => $s['total'],
				'applied'  => $s['applied'],
				'skipped'  => $s['skipped'],
				'failed'   => $s['failed'],
				'reverted' => $s['reverted'],
				'starts'   => $c->starts_at ? Clock::local( $c->starts_at ) : '-',
				'ends'     => $c->ends_at ? Clock::local( $c->ends_at ) : '-',
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'name', 'status', 'discount', 'target', 'total', 'applied', 'skipped', 'failed', 'reverted', 'starts', 'ends' ) );
	}

	/**
	 * Create a campaign from JSON.
	 *
	 * ## OPTIONS
	 *
	 * <json>
	 * : Campaign definition (same as the REST body).
	 *
	 * [--start]
	 * : Launch it right away.
	 *
	 * [--porcelain]
	 * : Print only the ID.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function create( $args, $assoc ) {
		$in = json_decode( $args[0], true );
		if ( ! is_array( $in ) ) {
			\WP_CLI::error( 'Invalid JSON.' );
		}
		$in['start'] = ! empty( $assoc['start'] );
		$c           = $this->service->create( $in );
		if ( is_wp_error( $c ) ) {
			\WP_CLI::error( $c->get_error_message() );
		}
		if ( ! empty( $assoc['porcelain'] ) ) {
			\WP_CLI::line( (string) $c->id );
			return;
		}
		\WP_CLI::success( "Campaign {$c->id} created ({$c->status})." );
	}

	/**
	 * Run lifecycle commands: start|pause|resume|stop|retry|duplicate|delete.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : Command.
	 *
	 * <id>
	 * : Campaign ID.
	 *
	 * @param array $args Args.
	 */
	public function campaign( $args ) {
		list( $cmd, $id ) = $args;
		if ( ! in_array( $cmd, array( 'start', 'pause', 'resume', 'stop', 'retry', 'duplicate', 'delete' ), true ) ) {
			\WP_CLI::error( 'Unknown command.' );
		}
		$r = call_user_func( array( $this->service, $cmd ), (int) $id );
		if ( is_wp_error( $r ) ) {
			\WP_CLI::error( $r->get_error_message() );
		}
		\WP_CLI::success( 'OK' . ( $r instanceof Campaign ? " ({$r->status})" : '' ) );
	}

	/**
	 * Process the queue in governed batches (each batch obeys the same limits as the web runner).
	 *
	 * ## OPTIONS
	 *
	 * [--max-batches=<n>]
	 * : Stop after n batches. Default: until there is no work.
	 *
	 * [--budget=<seconds>]
	 * : Per-batch time cap.
	 *
	 * [--quiet]
	 * : Less output.
	 *
	 * [--no-rest]
	 * : Skip the rest between batches (only on a server with no visitors).
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function run( $args, $assoc ) {
		$max    = isset( $assoc['max-batches'] ) ? (int) $assoc['max-batches'] : PHP_INT_MAX;
		$budget = isset( $assoc['budget'] ) ? (float) $assoc['budget'] : null;
		$n      = 0;
		$idle   = 0;
		while ( $n < $max ) {
			$r = $this->engine->pump(
				array(
					'max_seconds' => $budget,
					'source'      => 'cli',
					'chain'       => false,
				)
			);
			$n++;
			if ( empty( $r['ran'] ) ) {
				if ( 'idle' === $r['reason'] ) {
					break;
				}
				if ( 'cooling' === $r['reason'] ) {
					// The governor's rest between batches applies to the CLI runner too.
					Environment::pause( max( 1, min( 60, (int) $r['wait'] ) ) );
					$n--; // Resting is not a batch.
					continue;
				}
				if ( empty( $assoc['quiet'] ) ) {
					\WP_CLI::log( 'waiting: ' . $r['reason'] );
				}
				if ( ++$idle > 30 ) {
					\WP_CLI::warning( 'Worker not running: ' . $r['reason'] );
					break;
				}
				Environment::pause( 2 );
				continue;
			}
			$idle = 0;
			if ( empty( $assoc['quiet'] ) ) {
				$s = $r['summary'];
				\WP_CLI::log( sprintf( 'batch %d: %d items in %.2fs, peak %sMB, stop=%s, next=%d', $n, $s['items'], $s['secs'], $s['mem_mb'], $s['why'], $s['next'] ) );
			}
			if ( empty( $r['more'] ) ) {
				break;
			}
			// Same rest between batches as the web runners (protects shoppers on this host).
			if ( ! empty( $r['summary']['cool'] ) && empty( $assoc['no-rest'] ) ) {
				Environment::pause( (int) $r['summary']['cool'] );
			}
		}
		\WP_CLI::success( "Done after {$n} batch(es)." );
	}

	/**
	 * Run the watchdog once (time-based transitions).
	 */
	public function watchdog() {
		Watchdog::run();
		\WP_CLI::success( 'Watchdog ran.' );
	}

	/**
	 * Print a campaign's status and counts as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign ID.
	 *
	 * @param array $args Args.
	 */
	public function status( $args ) {
		$c = $this->repo->find( (int) $args[0] );
		if ( ! $c ) {
			\WP_CLI::error( 'Not found.' );
		}
		$data          = $c->to_api();
		$data['stats'] = $this->repo->counts( $c->id );
		\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Save a campaign report: product, ID, link, previous and new price.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Campaign ID.
	 *
	 * --file=<path>
	 * : Where to write the report.
	 *
	 * [--format=<format>]
	 * : xlsx (Excel) or html (the print page used for PDF). Default xlsx.
	 *
	 * [--status=<status>]
	 * : Only items in this status (applied, skipped, failed, reverted, kept, cancelled, pending).
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function export( $args, $assoc ) {
		$c = $this->repo->find( (int) $args[0] );
		if ( ! $c ) {
			\WP_CLI::error( 'Not found.' );
		}
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'xlsx';
		$status = isset( $assoc['status'] ) ? (string) $assoc['status'] : '';
		if ( ! in_array( $status, Report::STATUSES, true ) ) {
			\WP_CLI::error( 'Unknown status.' );
		}
		$export = new Export( $this->repo );
		if ( 'xlsx' === $format ) {
			$fh = fopen( (string) $assoc['file'], 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $fh ) {
				\WP_CLI::error( 'Cannot write ' . $assoc['file'] );
			}
			$n = $export->write_xlsx( $c, $status, $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( 'html' === $format ) {
			ob_start();
			$n = $export->render_print( $c, $status );
			file_put_contents( (string) $assoc['file'], ob_get_clean() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			\WP_CLI::error( 'Format must be xlsx or html.' );
			return;
		}
		\WP_CLI::success( sprintf( '%d rows written to %s', $n, $assoc['file'] ) );
	}
}
