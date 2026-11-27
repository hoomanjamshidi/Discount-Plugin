<?php
/**
 * Campaign report downloads: Excel (.xlsx) and a print-ready page for PDF.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * GET wp-admin/admin-post.php?action=dsd_export&id=…&format=xlsx|pdf&status=…&_wpnonce=…
 *
 * Read-only and light: items are read 500 at a time by primary key (one query per chunk
 * plus one cache-priming query for the links), no product objects are loaded, nothing is
 * written, and per-chunk runtime caches are dropped so memory stays flat on big campaigns.
 */
final class Export {

	const ACTION = 'dsd_export';
	const NONCE  = 'dsd_export';
	const CHUNK  = 500;

	/** @var Repository */
	private $repo;

	/**
	 * Constructor.
	 *
	 * @param Repository $repo Repo.
	 */
	public function __construct( Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Hooks (admin only: admin-post.php runs in the admin context).
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Download request.
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دیدن این گزارش را ندارید.', 'smart-bulk-discount' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_die(
				esc_html__( 'این لینک دانلود منقضی شده است. صفحهٔ تخفیف گروهی را دوباره باز کنید و دوباره روی دکمهٔ خروجی بزنید.', 'smart-bulk-discount' ),
				esc_html__( 'لینک منقضی شده', 'smart-bulk-discount' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! in_array( $status, Report::STATUSES, true ) ) {
			$status = '';
		}
		$c = $id ? $this->repo->find( $id ) : null;
		if ( ! $c ) {
			wp_die( esc_html__( 'این کمپین پیدا نشد.', 'smart-bulk-discount' ), '', array( 'response' => 404, 'back_link' => true ) );
		}
		if ( 'xlsx' === $format ) {
			$this->send_xlsx( $c, $status );
		} elseif ( 'pdf' === $format ) {
			$this->send_print( $c, $status );
		} else {
			wp_die( esc_html__( 'قالب خروجی نامعتبر است.', 'smart-bulk-discount' ), '', array( 'response' => 400 ) );
		}
		exit;
	}

	// ---------------------------------------------------------------------
	// Excel.
	// ---------------------------------------------------------------------

	/**
	 * Build the workbook into a stream (also used by `wp dsd export`).
	 *
	 * @param Campaign $c      Campaign.
	 * @param string   $status Item status filter ('' = all).
	 * @param resource $out    Writable stream.
	 * @return int Product rows written.
	 */
	public function write_xlsx( Campaign $c, $status, $out ) {
		$meta     = $this->meta( $c, $status );
		$cur      = $meta['currency'];
		$xlsx     = new Xlsx(
			array(
				'decimals' => $meta['decimals'],
				'rtl'      => true,
				'title'    => $meta['title'],
				'creator'  => 'Smart Bulk Discount',
			)
		);
		$in_money = '' !== $cur ? ' (' . $cur . ')' : '';
		$xlsx->add_sheet(
			'محصولات',
			array(
				array( 'title' => 'ردیف', 'type' => Xlsx::INT, 'width' => 7 ),
				array( 'title' => 'شناسهٔ محصول', 'type' => Xlsx::INT, 'width' => 12 ),
				array( 'title' => 'شناسهٔ محصول مادر', 'type' => Xlsx::INT, 'width' => 14 ),
				array( 'title' => 'نام محصول', 'type' => Xlsx::TEXT, 'width' => 46 ),
				array( 'title' => 'کد کالا (SKU)', 'type' => Xlsx::TEXT, 'width' => 16 ),
				array( 'title' => 'لینک محصول', 'type' => Xlsx::LINK, 'width' => 48 ),
				array( 'title' => 'قیمت قبلی' . $in_money, 'type' => Xlsx::MONEY, 'width' => 17 ),
				array( 'title' => 'حراج قبلی' . $in_money, 'type' => Xlsx::MONEY, 'width' => 17 ),
				array( 'title' => 'قیمت جدید' . $in_money, 'type' => Xlsx::MONEY, 'width' => 17 ),
				array( 'title' => 'مبلغ تخفیف' . $in_money, 'type' => Xlsx::MONEY, 'width' => 17 ),
				array( 'title' => 'درصد تخفیف', 'type' => Xlsx::PERCENT, 'width' => 12 ),
				array( 'title' => 'وضعیت', 'type' => Xlsx::TEXT, 'width' => 15 ),
				array( 'title' => 'توضیح', 'type' => Xlsx::TEXT, 'width' => 36 ),
			),
			array(
				'freeze' => true,
				'filter' => true,
			)
		);
		$n = 0;
		foreach ( $this->rows( $c->id, $status ) as $r ) {
			++$n;
			$xlsx->add_row(
				array(
					$n,
					$r['product_id'],
					$r['parent_id'] ? $r['parent_id'] : null,
					$r['name'],
					$r['sku'],
					'' === $r['url'] ? null : array(
						'url'  => $r['url'],
						'text' => Report::readable_url( $r['url'] ),
					),
					$r['regular'],
					$r['old_sale'],
					$r['new_price'],
					$r['saving'],
					$r['percent'],
					$r['status_fa'],
					$r['note'],
				)
			);
		}

		$xlsx->add_sheet(
			'خلاصه',
			array(
				array( 'type' => 'label', 'width' => 24 ),
				array( 'type' => Xlsx::TEXT, 'width' => 60 ),
			),
			array( 'header' => false )
		);
		foreach ( $this->summary( $meta, $n ) as $line ) {
			$xlsx->add_row( $line );
		}
		$xlsx->save( $out );
		return $n;
	}

	/**
	 * Send the workbook as a download.
	 *
	 * @param Campaign $c      Campaign.
	 * @param string   $status Filter.
	 */
	private function send_xlsx( Campaign $c, $status ) {
		$tmp = fopen( 'php://temp/maxmemory:8388608', 'w+b' );
		$this->write_xlsx( $c, $status, $tmp );
		$size = (int) ftell( $tmp );
		rewind( $tmp );

		$this->clean_buffers();
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: ' . $this->disposition( $c, 'xlsx' ) );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );
		fpassthru( $tmp );
		fclose( $tmp );
	}

	// ---------------------------------------------------------------------
	// Print page (PDF).
	// ---------------------------------------------------------------------

	/**
	 * Send the print-ready page. The browser opens its print dialog, where "Save as PDF"
	 * turns it into a PDF: Persian shaping, RTL and the Vazirmatn font come out perfectly,
	 * with no heavy PDF library inside the plugin.
	 *
	 * @param Campaign $c      Campaign.
	 * @param string   $status Filter.
	 */
	private function send_print( Campaign $c, $status ) {
		$this->clean_buffers();
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		$this->render_print( $c, $status, true );
	}

	/**
	 * Echo the print page (also used by `wp dsd export --format=html`).
	 *
	 * @param Campaign $c          Campaign.
	 * @param string   $status     Filter.
	 * @param bool     $auto_print Open the print dialog when the page is ready.
	 * @return int Product rows.
	 */
	public function render_print( Campaign $c, $status, $auto_print = false ) {
		$meta = $this->meta( $c, $status );
		$dec  = $meta['decimals'];
		$cur  = '' !== $meta['currency'] ? ' (' . $meta['currency'] . ')' : '';
		$css  = file_exists( DSD_DIR . 'build/index.css' ) ? DSD_URL . 'build/index.css?ver=' . rawurlencode( DSD_VERSION . '.' . filemtime( DSD_DIR . 'build/index.css' ) ) : '';

		echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">';
		echo '<title>' . esc_html( preg_replace( '/\.pdf$/', '', $meta['file_pdf'] ) ) . '</title>';
		if ( '' !== $css ) {
			// Only for its @font-face (Vazirmatn): every other rule there is scoped to #dsd-root.
			echo '<link rel="stylesheet" href="' . esc_url( $css ) . '">';
		}
		echo '<style>' . self::print_css() . '</style></head><body>';
		echo '<div class="bar"><span>' . esc_html__( 'برای ساختن فایل PDF، در پنجرهٔ چاپ مقصد را «Save as PDF» (ذخیره به‌صورت PDF) بگذارید.', 'smart-bulk-discount' ) . '</span>';
		echo '<button type="button" onclick="window.print()">' . esc_html__( 'چاپ / ذخیرهٔ PDF', 'smart-bulk-discount' ) . '</button></div>';

		echo '<main class="page"><header><p class="kicker">' . esc_html( $meta['site'] ) . ' · ' . esc_html__( 'گزارش کمپین تخفیف', 'smart-bulk-discount' ) . '</p>';
		echo '<h1>' . esc_html( $meta['name'] ) . '</h1><div class="meta">';
		foreach ( $this->summary( $meta, null ) as $line ) {
			if ( 'نام کمپین' === $line[0] ) {
				continue;
			}
			echo '<div><b>' . esc_html( $line[0] ) . '</b>' . esc_html( $line[1] ) . '</div>';
		}
		echo '</div></header>';

		echo '<table><colgroup><col class="c-n"><col class="c-id"><col class="c-name"><col class="c-url"><col class="c-p"><col class="c-p"><col class="c-off"><col class="c-st"></colgroup><thead><tr>';
		foreach ( array( 'ردیف', 'شناسه', 'نام محصول', 'لینک', 'قیمت قبلی' . $cur, 'قیمت جدید' . $cur, 'تخفیف', 'وضعیت' ) as $th ) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$n = 0;
		foreach ( $this->rows( $c->id, $status ) as $r ) {
			++$n;
			echo '<tr><td class="n">' . esc_html( Report::fa( $n ) ) . '</td>';
			echo '<td class="n">' . esc_html( Report::fa( $r['product_id'] ) );
			if ( $r['parent_id'] ) {
				echo '<span class="sub">' . esc_html__( 'مادر:', 'smart-bulk-discount' ) . ' ' . esc_html( Report::fa( $r['parent_id'] ) ) . '</span>';
			}
			echo '</td><td>' . esc_html( $r['name'] );
			if ( '' !== $r['sku'] ) {
				echo '<span class="sub">' . esc_html__( 'کد کالا:', 'smart-bulk-discount' ) . ' <bdi>' . esc_html( $r['sku'] ) . '</bdi></span>';
			}
			echo '</td><td>';
			if ( '' !== $r['url'] ) {
				echo '<a class="url" href="' . esc_url( $r['url'] ) . '"><bdi dir="ltr">' . esc_html( Report::short_url( $r['url'] ) ) . '</bdi></a>';
			}
			echo '</td><td class="n">' . esc_html( Report::num( $r['regular'], $dec ) );
			if ( null !== $r['old_sale'] ) {
				echo '<span class="sub">' . esc_html__( 'حراج قبلی:', 'smart-bulk-discount' ) . ' ' . esc_html( Report::num( $r['old_sale'], $dec ) ) . '</span>';
			}
			echo '</td><td class="n strong">' . esc_html( Report::num( $r['new_price'], $dec ) ) . '</td>';
			echo '<td class="n">';
			if ( null !== $r['percent'] ) {
				echo '<span class="off">' . esc_html( Report::percent( $r['percent'] ) ) . '</span><span class="sub">' . esc_html( Report::num( $r['saving'], $dec ) ) . '</span>';
			} else {
				echo '—';
			}
			echo '</td><td class="st">' . esc_html( $r['status_fa'] );
			if ( '' !== $r['note'] ) {
				echo '<span class="sub">' . esc_html( $r['note'] ) . '</span>';
			}
			echo '</td></tr>';
			if ( 0 === $n % self::CHUNK ) {
				flush();
			}
		}
		echo '</tbody></table>';
		if ( 0 === $n ) {
			echo '<p class="empty">' . esc_html__( 'در این فیلتر محصولی نیست. فهرست محصولات بعد از شروع کمپین ساخته می‌شود.', 'smart-bulk-discount' ) . '</p>';
		}
		echo '<p class="foot">' . esc_html( sprintf( 'تهیه‌شده در %s · %s ردیف', $meta['generated'], Report::num( $n ) ) ) . '</p></main>';
		if ( $auto_print && $n > 0 ) {
			echo '<script>(function(){var go=function(){setTimeout(function(){window.print();},300);};if(document.fonts&&document.fonts.ready){document.fonts.ready.then(go,go);}else{window.addEventListener("load",go);}})();</script>';
		}
		echo '</body></html>';
		return $n;
	}

	/**
	 * Print stylesheet.
	 *
	 * @return string
	 */
	private static function print_css() {
		return '@page{size:A4 landscape;margin:11mm 9mm 13mm;@bottom-center{content:counter(page) " / " counter(pages);font:9px Tahoma,sans-serif;color:#64748b}}'
			. ':root{--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--brand:#0f766e;--soft:#f1f5f9}'
			. '*{box-sizing:border-box}'
			. 'html,body{margin:0;background:#fff;color:var(--ink);font-family:"Vazirmatn Variable",Vazirmatn,Tahoma,"Segoe UI",sans-serif;font-size:11px;line-height:1.65;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
			. '.bar{position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;padding:12px 24px;background:#ecfdf5;border-bottom:1px solid #a7f3d0;font-size:13px}'
			. '.bar button{font:inherit;font-weight:700;background:var(--brand);color:#fff;border:0;border-radius:999px;padding:8px 20px;cursor:pointer}'
			. '.page{padding:20px 24px 28px}'
			. '.kicker{margin:0;color:var(--muted);font-size:10.5px}h1{margin:2px 0 0;font-size:19px;font-weight:800}'
			. '.meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin:12px 0 14px}'
			. '.meta div{background:var(--soft);border-radius:8px;padding:6px 10px;font-weight:600}'
			. '.meta b{display:block;font-size:9.5px;color:var(--muted);font-weight:500}'
			. 'table{width:100%;border-collapse:collapse;table-layout:fixed}thead{display:table-header-group}tr{break-inside:avoid;page-break-inside:avoid}'
			. '.c-n{width:4%}.c-id{width:7%}.c-name{width:30%}.c-url{width:19%}.c-p{width:10.5%}.c-off{width:8%}.c-st{width:11%}'
			. 'th{background:var(--brand);color:#fff;font-weight:700;text-align:start;padding:6px;font-size:10px}'
			. 'td{border-bottom:1px solid var(--line);padding:5px 6px;vertical-align:top;overflow-wrap:anywhere}'
			. 'tbody tr:nth-child(even) td{background:#f8fafc}'
			. '.n{font-variant-numeric:tabular-nums;white-space:nowrap}.strong{font-weight:700}'
			. '.sub{display:block;color:var(--muted);font-size:9px;font-weight:400;white-space:normal}'
			. '.url{display:block;text-align:left;font-size:9px;color:#0369a1;text-decoration:none;overflow-wrap:anywhere}.url bdi{unicode-bidi:isolate}'
			. '.off{color:#047857;font-weight:700}'
			. '.empty{padding:32px;text-align:center;color:var(--muted)}.foot{margin:12px 0 0;color:var(--muted);font-size:9.5px}'
			. '@media print{.bar{display:none}.page{padding:0}}';
	}

	// ---------------------------------------------------------------------
	// Data.
	// ---------------------------------------------------------------------

	/**
	 * Report rows, chunk by chunk.
	 *
	 * @param int    $campaign_id Campaign.
	 * @param string $status      Filter.
	 * @return \Generator<array>
	 */
	public function rows( $campaign_id, $status ) {
		$after = 0;
		do {
			$items = $this->repo->export_chunk( $campaign_id, $status, $after, self::CHUNK );
			if ( empty( $items ) ) {
				break;
			}
			list( $urls, $variants ) = $this->links( $items );
			foreach ( $items as $it ) {
				$after = (int) $it->id;
				$pid   = (int) $it->product_id;
				yield Report::row( $it, isset( $urls[ $pid ] ) ? $urls[ $pid ] : '', isset( $variants[ $pid ] ) ? $variants[ $pid ] : '' );
			}
			self::release_memory();
		} while ( count( $items ) === self::CHUNK );
	}

	/**
	 * Public product links for one chunk: one query primes the posts, permalinks come
	 * from the cache; a variation gets WooCommerce's own link (parent + its attributes).
	 *
	 * @param object[] $items Chunk.
	 * @return array{0: array<int, string>, 1: array<int, string>} [product_id => URL, variation_id => attribute values].
	 */
	private function links( array $items ) {
		$pages      = array();
		$variations = array();
		foreach ( $items as $it ) {
			$pages[] = (int) ( $it->parent_id ? $it->parent_id : $it->product_id );
			if ( $it->parent_id ) {
				$variations[] = (int) $it->product_id;
			}
		}
		$pages = TargetResolver::int_list( $pages );
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $pages, true, false );
		}
		$base = array();
		foreach ( $pages as $id ) {
			$url         = get_permalink( $id );
			$base[ $id ] = $url ? (string) $url : '';
		}
		$attrs    = $this->repo->variation_attributes( $variations );
		$out      = array();
		$variants = array();
		foreach ( $items as $it ) {
			$pid = (int) $it->product_id;
			if ( $it->parent_id ) {
				$own              = isset( $attrs[ $pid ] ) ? $attrs[ $pid ] : array();
				$parent           = isset( $base[ (int) $it->parent_id ] ) ? $base[ (int) $it->parent_id ] : '';
				$out[ $pid ]      = Report::variation_url( $parent, $own );
				$variants[ $pid ] = Report::variant_label( $own );
			} else {
				$out[ $pid ] = isset( $base[ $pid ] ) ? $base[ $pid ] : '';
			}
		}
		return array( $out, $variants );
	}

	/**
	 * Header facts shared by both formats.
	 *
	 * @param Campaign $c      Campaign.
	 * @param string   $status Filter.
	 * @return array
	 */
	private function meta( Campaign $c, $status ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '';
		$name     = Report::plain( $c->name );
		$stamp    = self::stamp();

		return array(
			'name'      => '' !== $name ? $name : '#' . $c->id,
			'title'     => 'گزارش کمپین ' . $name,
			'site'      => Report::plain( get_bloginfo( 'name' ) ),
			'decimals'  => $decimals,
			'currency'  => $currency,
			'discount'  => 'percent' === $c->discount_type ? Report::num( $c->discount_value, 2 ) . '٪' : Report::num( $c->discount_value, $decimals ) . ( '' !== $currency ? ' ' . $currency : '' ),
			'target'    => $this->target_text( $c ),
			'starts'    => $c->starts_at ? Report::jalali( Clock::local( $c->starts_at ) ) : '—',
			'ends'      => $c->ends_at ? Report::jalali( Clock::local( $c->ends_at ) ) : 'تا توقف دستی',
			'status'    => isset( Report::CAMPAIGN_STATUS[ $c->status ] ) ? Report::CAMPAIGN_STATUS[ $c->status ] : $c->status,
			'filter'    => '' === $status ? 'همهٔ محصولات کمپین' : ( isset( Report::ITEM_STATUS[ $status ] ) ? 'فقط «' . Report::ITEM_STATUS[ $status ] . '»' : $status ),
			'counts'    => $this->repo->counts( $c->id ),
			'generated' => Report::jalali( Clock::local( time() ) ),
			'file_xlsx' => Report::file_name( $name, $stamp, 'xlsx' ),
			'file_pdf'  => Report::file_name( $name, $stamp, 'pdf' ),
		);
	}

	/**
	 * Label/value lines for the summary sheet and the PDF header.
	 *
	 * @param array    $meta Meta.
	 * @param int|null $rows Product rows in this file (null = not shown).
	 * @return array[]
	 */
	private function summary( array $meta, $rows ) {
		$k     = $meta['counts'];
		$lines = array(
			array( 'نام کمپین', $meta['name'] ),
			array( 'تخفیف', $meta['discount'] ),
			array( 'محصولات هدف', $meta['target'] ),
			array( 'وضعیت کمپین', $meta['status'] ),
			array( 'شروع', $meta['starts'] ),
			array( 'پایان', $meta['ends'] ),
			array( 'محصولات این گزارش', $meta['filter'] ),
			array( 'تهیه‌شده در', $meta['generated'] ),
			array( 'کل اقلام کمپین', Report::num( $k['total'] ) ),
			array( 'با تخفیف (فعال)', Report::num( $k['applied'] ) ),
			array( 'برگشته / دست‌نخورده', Report::num( $k['reverted'] + $k['kept'] ) ),
			array( 'رد شده / ناموفق / لغو', Report::num( $k['skipped'] + $k['failed'] + $k['cancelled'] ) ),
		);
		if ( null !== $rows ) {
			$lines[] = array( 'ردیف‌های این فایل', Report::num( $rows ) );
		}
		return $lines;
	}

	/**
	 * "همهٔ محصولات موجود" / category names / "۱۲ محصول انتخابی".
	 *
	 * @param Campaign $c Campaign.
	 * @return string
	 */
	private function target_text( Campaign $c ) {
		if ( Campaign::TARGET_ALL === $c->target_type ) {
			return 'همهٔ محصولات موجود';
		}
		if ( Campaign::TARGET_CATEGORIES === $c->target_type ) {
			$ids   = TargetResolver::int_list( isset( $c->target['category_ids'] ) ? (array) $c->target['category_ids'] : array() );
			$names = array();
			foreach ( array_slice( $ids, 0, 8 ) as $tid ) {
				$t = get_term( $tid, 'product_cat' );
				if ( $t && ! is_wp_error( $t ) ) {
					$names[] = Report::plain( $t->name );
				}
			}
			$more = count( $ids ) > 8 ? ' و ' . Report::num( count( $ids ) - 8 ) . ' دستهٔ دیگر' : '';
			return 'دسته‌ها: ' . ( $names ? implode( '، ', $names ) : Report::num( count( $ids ) ) ) . $more;
		}
		$ids = isset( $c->target['product_ids'] ) ? (array) $c->target['product_ids'] : array();
		return Report::num( count( $ids ) ) . ' محصول انتخابی';
	}

	// ---------------------------------------------------------------------
	// HTTP helpers.
	// ---------------------------------------------------------------------

	/**
	 * Content-Disposition with a Persian file name (RFC 6266) and an ASCII fallback.
	 *
	 * @param Campaign $c   Campaign.
	 * @param string   $ext Extension.
	 * @return string
	 */
	private function disposition( Campaign $c, $ext ) {
		$utf8  = Report::file_name( $c->name, self::stamp(), $ext );
		$ascii = 'campaign-' . (int) $c->id . '-' . gmdate( 'Ymd' ) . '.' . $ext;
		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $utf8 );
	}

	/**
	 * Today's site date in the Jalali calendar, Latin digits ("1405-09-06"), for file names.
	 *
	 * @return string
	 */
	private static function stamp() {
		$today            = Clock::local( time(), 'Y-m-d' );
		list( $y, $m, $d ) = Clock::gregorian_to_jalali( (int) substr( $today, 0, 4 ), (int) substr( $today, 5, 2 ), (int) substr( $today, 8, 2 ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * Drop output buffers (a stray byte would corrupt the file) and on-the-fly gzip
	 * (it would break Content-Length).
	 */
	private function clean_buffers() {
		if ( function_exists( 'ini_get' ) && ini_get( 'zlib.output_compression' ) && function_exists( 'ini_set' ) ) {
			ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	/**
	 * Forget per-request caches between chunks so memory stays flat on big campaigns.
	 * Only the in-memory layer: a persistent object cache (Redis…) is left alone.
	 */
	private static function release_memory() {
		if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} elseif ( ! wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}
	}
}
