<?php
/**
 * REST API for the admin app: /wp-json/dsd/v1/...
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Routes. Every route requires manage_woocommerce (+ the wp_rest nonce via apiFetch).
 */
final class RestController {

	const NS = 'dsd/v1';

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
	 * Capability gate.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Register routes.
	 */
	public function register() {
		$perm = array( $this, 'can_manage' );
		$id   = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/campaigns',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_campaigns' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_campaign' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/campaigns/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_campaign' ),
					'permission_callback' => $perm,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_campaign' ),
					'permission_callback' => $perm,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_campaign' ),
					'permission_callback' => $perm,
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/campaigns/(?P<id>\d+)/(?P<command>start|pause|resume|stop|retry|duplicate)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'command' ),
				'permission_callback' => $perm,
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/campaigns/(?P<id>\d+)/items',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'items' ),
				'permission_callback' => $perm,
				'args'                => array_merge(
					$id,
					array(
						'status'   => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array( '', 'pending', 'applied', 'skipped', 'failed', 'reverted', 'kept', 'cancelled' ),
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 200,
						),
					)
				),
			)
		);
		register_rest_route(
			self::NS,
			'/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_products' ),
				'permission_callback' => $perm,
				'args'                => array(
					'q'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'stock'    => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array( '', 'instock', 'outofstock', 'onbackorder' ),
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'include'  => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/categories',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'categories' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/system',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'system' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/tick',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'tick' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $perm,
				),
			)
		);
	}

	/**
	 * GET /campaigns.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_campaigns() {
		return rest_ensure_response(
			array(
				'campaigns' => array_map( array( $this, 'present' ), $this->repo->all() ),
				'worker'    => $this->worker_state(),
			)
		);
	}

	/**
	 * POST /campaigns.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_campaign( \WP_REST_Request $req ) {
		$c = $this->service->create( (array) $req->get_json_params() );
		return is_wp_error( $c ) ? $c : new \WP_REST_Response( $this->present( $c ), 201 );
	}

	/**
	 * GET /campaigns/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_campaign( \WP_REST_Request $req ) {
		$c = $this->repo->find( (int) $req['id'] );
		if ( ! $c ) {
			return new \WP_Error( 'dsd_not_found', CampaignService::message( 'not_found' ), array( 'status' => 404 ) );
		}
		$data           = $this->present( $c );
		$data['stats']  = array_merge( $data['stats'], $this->repo->counts( $c->id ) );
		$data['worker'] = $this->worker_state();
		return rest_ensure_response( $data );
	}

	/**
	 * PUT /campaigns/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_campaign( \WP_REST_Request $req ) {
		$c = $this->service->update( (int) $req['id'], (array) $req->get_json_params() );
		return is_wp_error( $c ) ? $c : rest_ensure_response( $this->present( $c ) );
	}

	/**
	 * DELETE /campaigns/{id}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_campaign( \WP_REST_Request $req ) {
		$r = $this->service->delete( (int) $req['id'] );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /campaigns/{id}/{command}.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function command( \WP_REST_Request $req ) {
		$id      = (int) $req['id'];
		$command = (string) $req['command'];
		$map     = array(
			'start'     => 'start',
			'pause'     => 'pause',
			'resume'    => 'resume',
			'stop'      => 'stop',
			'retry'     => 'retry',
			'duplicate' => 'duplicate',
		);
		$c = call_user_func( array( $this->service, $map[ $command ] ), $id );
		return is_wp_error( $c ) ? $c : rest_ensure_response( $this->present( $c ) );
	}

	/**
	 * GET /campaigns/{id}/items.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function items( \WP_REST_Request $req ) {
		$page = $this->repo->items_page( (int) $req['id'], (string) $req['status'], (int) $req['page'], (int) $req['per_page'] );
		$out  = array();
		foreach ( $page['items'] as $r ) {
			$name  = $r->post_title ? $r->post_title : '#' . $r->product_id;
			$out[] = array(
				'id'           => (int) $r->id,
				'product_id'   => (int) $r->product_id,
				'parent_id'    => (int) $r->parent_id,
				'name'         => $r->parent_id && $r->parent_title && false === strpos( (string) $name, (string) $r->parent_title ) ? $r->parent_title . ' — ' . $name : $name,
				'status'       => $r->status,
				'orig_regular' => $r->orig_regular,
				'orig_sale'    => $r->orig_sale,
				'new_sale'     => $r->new_sale,
				'note'         => $r->note,
				'edit_url'     => get_edit_post_link( $r->parent_id ? (int) $r->parent_id : (int) $r->product_id, 'raw' ),
			);
		}
		$res = rest_ensure_response( array( 'items' => $out, 'total' => $page['total'] ) );
		return $res;
	}

	/**
	 * POST /preview.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( \WP_REST_Request $req ) {
		$r = $this->service->preview( (array) $req->get_json_params() );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	/**
	 * GET /products — search by ID, SKU or name, optionally within a category.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function search_products( \WP_REST_Request $req ) {
		global $wpdb;
		$q        = trim( (string) $req['q'] );
		$per_page = (int) $req['per_page'];
		$page     = (int) $req['page'];
		$include  = TargetResolver::int_list( (array) $req['include'] );

		$where = array( "p.post_type = 'product'", "p.post_status IN ('publish','private')" );
		$joins = '';
		if ( ! empty( $include ) ) {
			$where[] = 'p.ID IN (' . implode( ',', $include ) . ')';
		} elseif ( '' !== $q ) {
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$sku  = $wpdb->prepare(
				"p.ID IN (SELECT pm.post_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_sku' AND pm.meta_value LIKE %s
				UNION SELECT v.post_parent FROM {$wpdb->posts} v INNER JOIN {$wpdb->postmeta} vm ON vm.post_id = v.ID AND vm.meta_key = '_sku'
				WHERE v.post_type = 'product_variation' AND vm.meta_value LIKE %s)",
				$like,
				$like
			);
			$text = $wpdb->prepare( 'p.post_title LIKE %s', $like );
			$or   = array( $text, $sku );
			if ( ctype_digit( ltrim( $q, '#' ) ) ) {
				$or[] = $wpdb->prepare( 'p.ID = %d', (int) ltrim( $q, '#' ) );
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		if ( (int) $req['category'] > 0 ) {
			$tt_ids = array();
			foreach ( TargetResolver::with_descendants( array( (int) $req['category'] ) ) as $term_id ) {
				$ct = get_term( $term_id, 'product_cat' );
				if ( $ct && ! is_wp_error( $ct ) ) {
					$tt_ids[] = (int) $ct->term_taxonomy_id;
				}
			}
			if ( $tt_ids ) {
				$where[] = "p.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', $tt_ids ) . '))';
			}
		}
		if ( '' !== (string) $req['stock'] ) {
			$joins  .= " INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock_status'";
			$where[] = $wpdb->prepare( 'st.meta_value = %s', (string) $req['stock'] );
		}

		$sql_where = implode( ' AND ', $where );
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p {$joins} WHERE {$sql_where}" ); // phpcs:ignore
		$ids       = $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p {$joins} WHERE {$sql_where} ORDER BY p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ) // phpcs:ignore
		);

		$out = array();
		foreach ( $ids as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( ! $p ) {
				continue;
			}
			$out[] = $this->present_product( $p );
		}
		return rest_ensure_response(
			array(
				'products' => $out,
				'total'    => $total,
				'pages'    => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Compact product shape for the picker.
	 *
	 * @param \WC_Product $p Product.
	 * @return array
	 */
	private function present_product( $p ) {
		$image = wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
		$cats  = wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$data  = array(
			'id'         => $p->get_id(),
			'name'       => wp_strip_all_tags( $p->get_name() ),
			'sku'        => (string) $p->get_sku(),
			'type'       => $p->get_type(),
			'stock'      => $p->get_stock_status(),
			'regular'    => '' === $p->get_regular_price( 'edit' ) ? null : (float) $p->get_regular_price( 'edit' ),
			'sale'       => '' === $p->get_sale_price( 'edit' ) ? null : (float) $p->get_sale_price( 'edit' ),
			'image'      => $image ? $image : null,
			'categories' => is_wp_error( $cats ) ? array() : array_values( $cats ),
			'variations' => 0,
			'locked_by'  => (int) $p->get_meta( '_dsd_campaign', true, 'edit' ),
		);
		if ( $p->is_type( 'variable' ) ) {
			$prices             = $p->get_variation_prices( false );
			$data['variations'] = count( $p->get_children() );
			$data['regular']    = empty( $prices['regular_price'] ) ? null : (float) min( $prices['regular_price'] );
			$data['regular_max'] = empty( $prices['regular_price'] ) ? null : (float) max( $prices['regular_price'] );
		}
		return $data;
	}

	/**
	 * GET /categories — flat list with depth and product counts.
	 *
	 * @return \WP_REST_Response
	 */
	public function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $by_parent ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $t ) {
				$out[] = array(
					'id'     => (int) $t->term_id,
					'name'   => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
					'parent' => (int) $t->parent,
					'depth'  => $depth,
					'count'  => (int) $t->count,
				);
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return rest_ensure_response( $out );
	}

	/**
	 * GET /system — host health + governor state for the "server" panel.
	 *
	 * @return \WP_REST_Response
	 */
	public function system() {
		$env      = new Environment();
		$settings = Settings::all();
		$gov      = new ResourceGovernor( $env, $settings['profile'], (array) get_option( 'dsd_governor', array() ), ! empty( $settings['pause_on_high_load'] ) );
		$state    = $gov->state();
		$load     = $env->load_average();

		return rest_ensure_response(
			array(
				'php_version'        => PHP_VERSION,
				'wc_version'         => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'memory_limit_mb'    => round( $env->memory_limit() / 1048576 ),
				'max_execution_time' => $env->max_execution_time(),
				'cpu_count'          => $env->cpu_count(),
				'load'               => null === $load ? null : round( $load, 2 ),
				'load_ratio'         => null === $gov->load_ratio() ? null : round( $gov->load_ratio(), 2 ),
				'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'action_scheduler'   => Queue::has_as(),
				'object_cache'       => wp_using_ext_object_cache(),
				'profile'            => $gov->profile(),
				'batch_size'         => (int) $state['batch_size'],
				'ms_per_item'        => (float) $state['ms_per_item'],
				'base_ms'            => (float) $state['base_ms'],
				'pace'               => (float) $state['pace'],
				'duty'               => round( $gov->duty(), 2 ),
				'headroom_mb'        => round( $gov->headroom() / 1048576 ),
				'cooling'            => Engine::cooling_left(),
				'loopback_enabled'   => (bool) $settings['loopback'],
				'last_kick'          => (int) get_option( 'dsd_kick_sent', 0 ),
				'caches'             => CacheCompat::detected(),
				'cache_compat'       => CacheCompat::enabled(),
				'last_purge'         => get_option( 'dsd_last_purge', null ),
				'site_timezone'      => Clock::timezone()->getName(),
				'site_now'           => Clock::local( time() ),
				'time_budget'        => round( $gov->time_budget(), 1 ),
				'history'            => array_values( (array) $state['history'] ),
				'total_items'        => (int) $state['total_items'],
				'worker'             => $this->worker_state(),
				'last_skip'          => get_option( 'dsd_last_skip', null ),
			)
		);
	}

	/**
	 * POST /tick — the open admin page helps the queue along when background runners lag.
	 * Runs a short (≤8s) governed batch only if no batch ran in the last 20 seconds.
	 *
	 * @return \WP_REST_Response
	 */
	public function tick() {
		$result = array(
			'ran'    => false,
			'reason' => 'not_needed',
		);
		$last   = (int) get_option( 'dsd_heartbeat', 0 );
		if ( Settings::get( 'admin_runner' ) && ( time() - $last ) >= 20 ) {
			$result = $this->engine->pump(
				array(
					'max_seconds' => 8,
					'source'      => 'admin',
					'chain'       => true,
				)
			);
		} else {
			Watchdog::check_times( $this->repo );
		}
		$result['worker'] = $this->worker_state();
		return rest_ensure_response( $result );
	}

	/**
	 * GET /settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( Settings::all() );
	}

	/**
	 * PUT /settings.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $req ) {
		return rest_ensure_response( Settings::update( (array) $req->get_json_params() ) );
	}

	/**
	 * Campaign + local time strings for the UI.
	 *
	 * @param Campaign $c Campaign.
	 * @return array
	 */
	private function present( Campaign $c ) {
		$data                 = $c->to_api();
		// Never wp_date(): Persian date plugins filter it into Jalali dates / Persian digits.
		$data['starts_local'] = Clock::local( $c->starts_at );
		$data['ends_local']   = Clock::local( $c->ends_at );
		return $data;
	}

	/**
	 * Worker heartbeat summary.
	 *
	 * @return array
	 */
	private function worker_state() {
		return array(
			'heartbeat'    => (int) get_option( 'dsd_heartbeat', 0 ),
			'now'          => time(),
			'busy'         => ! Lock::is_free( 'worker' ),
			'queued'       => Queue::pump_pending(),
			'has_work'     => null !== $this->repo->next_working(),
		);
	}
}
