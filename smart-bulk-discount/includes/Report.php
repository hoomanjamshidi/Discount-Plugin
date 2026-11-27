<?php
/**
 * Campaign report rows: prices, discount, labels (shared by the Excel and PDF exports).
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers (no WordPress): unit tested.
 */
final class Report {

	const STATUSES = array( '', 'pending', 'applied', 'skipped', 'failed', 'reverted', 'kept', 'cancelled' );

	/** Item statuses whose new_sale was really on the product at some point. */
	const PRICED = array( 'applied', 'reverted', 'kept' );

	const ITEM_STATUS = array(
		'pending'   => 'در صف',
		'applied'   => 'اعمال شد',
		'skipped'   => 'رد شد',
		'failed'    => 'ناموفق',
		'reverted'  => 'برگشت',
		'kept'      => 'دست‌نخورده ماند',
		'cancelled' => 'لغو',
	);

	const CAMPAIGN_STATUS = array(
		'draft'            => 'پیش‌نویس',
		'scheduled'        => 'زمان‌بندی شده',
		'preparing'        => 'در حال آماده‌سازی',
		'applying'         => 'در حال اعمال',
		'verifying'        => 'در حال بررسی',
		'active'           => 'فعال',
		'reverting'        => 'در حال برگرداندن',
		'verifying_revert' => 'بررسی نهایی',
		'ended'            => 'تمام شده',
		'paused'           => 'متوقف موقت',
	);

	const NOTES = array(
		'not_found'          => 'محصول پیدا نشد',
		'unsupported_type'   => 'نوع محصول پشتیبانی نمی‌شود',
		'out_of_stock'       => 'ناموجود است',
		'other_campaign'     => 'در کمپین دیگری است',
		'no_price'           => 'قیمت ندارد',
		'price_invalid'      => 'تخفیف بیشتر از قیمت است',
		'has_own_sale'       => 'تخفیف خودش را دارد',
		'own_sale_is_better' => 'تخفیف فعلی‌اش بیشتر است',
		'manual_change'      => 'قیمت دستی عوض شده بود',
		'lock_changed'       => 'توسط کمپین دیگری تغییر کرد',
		'verify_mismatch'    => 'بررسی ناموفق؛ دوباره تلاش شد',
		'revert_mismatch'    => 'برگرداندن دوباره تلاش شد',
		'crashed'            => 'هنگام ذخیرهٔ این محصول، افزونهٔ دیگری خطای مرگبار داد',
		'interrupted'        => 'کار روی این محصول نیمه‌کاره ماند؛ قیمت اصلی بررسی و برگردانده شد',
		'failed_apply'       => 'اعمال ناموفق بود؛ قیمت اصلی بررسی و برگردانده شد',
	);

	const MONTHS = array( 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

	/**
	 * A stored price string → float, or null when empty / not a number.
	 *
	 * @param mixed $v Value.
	 * @return float|null
	 */
	public static function price( $v ) {
		if ( null === $v || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		return (float) $v;
	}

	/**
	 * One normalized report row.
	 *
	 * Previous price = the backup taken before the first write. An item without a backup
	 * (skipped, cancelled, still queued) was never touched, so its current price is its
	 * previous price (cur_regular / cur_sale).
	 *
	 * @param object|array $item    dsd_items row (+ post_title, parent_title, sku, parent_sku, cur_regular, cur_sale).
	 * @param string       $url     Product link ('' if unknown).
	 * @param string       $variant A variation's attribute values ("قرمز، XL"), see variant_label().
	 * @return array
	 */
	public static function row( $item, $url = '', $variant = '' ) {
		$item    = (object) $item;
		$status  = (string) $item->status;
		$backup  = null !== self::price( $item->orig_regular );
		$regular = self::price( $backup ? $item->orig_regular : ( isset( $item->cur_regular ) ? $item->cur_regular : null ) );
		$old     = self::price( $backup ? $item->orig_sale : ( isset( $item->cur_sale ) ? $item->cur_sale : null ) );
		if ( null === $regular || null === $old || $old <= 0 || $old >= $regular ) {
			$old = null; // Not a real sale price (none, or not below the regular price).
		}
		$new = in_array( $status, self::PRICED, true ) ? self::price( $item->new_sale ) : null;

		$saving  = null;
		$percent = null;
		if ( null !== $regular && null !== $new && $regular > 0 ) {
			$saving  = round( $regular - $new, 6 );
			$percent = round( $saving / $regular, 4 );
		}

		$sku = isset( $item->sku ) ? trim( (string) $item->sku ) : '';
		if ( '' === $sku && isset( $item->parent_sku ) ) {
			$sku = trim( (string) $item->parent_sku ); // WooCommerce shows the parent SKU for a variation without its own.
		}

		return array(
			'product_id' => (int) $item->product_id,
			'parent_id'  => (int) $item->parent_id,
			'name'       => self::display_name(
				isset( $item->post_title ) ? $item->post_title : '',
				isset( $item->parent_title ) ? $item->parent_title : '',
				(int) $item->product_id,
				(int) $item->parent_id,
				$variant
			),
			'sku'        => $sku,
			'url'        => (string) $url,
			'status'     => $status,
			'status_fa'  => isset( self::ITEM_STATUS[ $status ] ) ? self::ITEM_STATUS[ $status ] : $status,
			'note'       => self::note_label( isset( $item->note ) ? (string) $item->note : '' ),
			'regular'    => $regular,
			'old_sale'   => $old,
			'new_price'  => $new,
			'saving'     => $saving,
			'percent'    => $percent,
		);
	}

	/**
	 * "Parent — Variation" unless the variation title already contains the parent's. A
	 * variation titled exactly like its parent (imports often do that) gets its attribute
	 * values, so rows of one product stay distinguishable.
	 *
	 * @param string $title        Product (or variation) title.
	 * @param string $parent_title Parent title ('' for simple products).
	 * @param int    $product_id   ID.
	 * @param int    $parent_id    Parent ID.
	 * @param string $variant      Attribute values ("قرمز، XL"), '' if unknown.
	 * @return string
	 */
	public static function display_name( $title, $parent_title, $product_id, $parent_id, $variant = '' ) {
		$title        = self::plain( $title );
		$parent_title = self::plain( $parent_title );
		$name         = '' !== $title ? $title : '#' . (int) $product_id;
		if ( $parent_id && '' !== $parent_title && false === strpos( $name, $parent_title ) ) {
			$name = $parent_title . ' — ' . $name;
		}
		$variant = trim( (string) $variant );
		if ( $parent_id && '' !== $variant && ( '' === $title || $title === $parent_title ) ) {
			$name = ( '' !== $parent_title ? $parent_title : $name ) . ' — ' . $variant;
		}
		return $name;
	}

	/**
	 * A variation's attribute values as people read them: "قرمز، XL". Taxonomy values are
	 * term slugs (Persian ones percent-encoded); they are decoded and de-hyphenated.
	 *
	 * @param array $attributes meta_key (attribute_*) => value.
	 * @return string
	 */
	public static function variant_label( array $attributes ) {
		ksort( $attributes );
		$out = array();
		foreach ( $attributes as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			if ( 0 === strpos( (string) $key, 'attribute_pa_' ) ) {
				$value = str_replace( '-', ' ', rawurldecode( $value ) );
			}
			$out[] = self::plain( $value );
		}
		return implode( '، ', array_filter( $out, 'strlen' ) );
	}

	/**
	 * Short address for print: no scheme, no query, decoded ("shop.ir/product/کفش/").
	 * The full link (with a variation's attributes) stays in the href.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function short_url( $url ) {
		$url = (string) preg_replace( '#^https?://(www\.)?#i', '', (string) $url );
		$cut = strcspn( $url, '?#' );
		$q   = substr( $url, $cut );
		$url = substr( $url, 0, $cut );
		// Plain permalinks (?product=slug / ?p=12) keep their query: it is the address.
		if ( '' !== $q && ( '' === trim( $url, '/' ) || false === strpos( trim( $url, '/' ), '/' ) ) && ! preg_match( '/^\?attribute_/', $q ) ) {
			$url .= (string) preg_replace( '/&attribute_.*$/', '', $q );
		}
		return self::readable_url( $url );
	}

	/**
	 * Title as plain text (titles may carry entities or tags).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function plain( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = (string) preg_replace( '/<[^>]*>/', '', $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/**
	 * Item note → Persian.
	 *
	 * @param string $note Note code.
	 * @return string
	 */
	public static function note_label( $note ) {
		$note = (string) $note;
		if ( '' === $note ) {
			return '';
		}
		if ( 0 === strpos( $note, 'error:' ) ) {
			return 'خطا: ' . trim( substr( $note, 6 ) );
		}
		return isset( self::NOTES[ $note ] ) ? self::NOTES[ $note ] : $note;
	}

	/**
	 * The link WooCommerce itself gives a variation: the parent's page with the variation's
	 * attributes in the query (the product page then pre-selects it). Empty values = "any".
	 *
	 * @param string $base       Parent permalink.
	 * @param array  $attributes meta_key (attribute_*) => value.
	 * @return string
	 */
	public static function variation_url( $base, array $attributes ) {
		$base = (string) $base;
		if ( '' === $base ) {
			return '';
		}
		ksort( $attributes );
		$query = array();
		foreach ( $attributes as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$query[] = urlencode( (string) $key ) . '=' . urlencode( (string) $value );
		}
		if ( empty( $query ) ) {
			return $base;
		}
		$hash = '';
		$pos  = strpos( $base, '#' );
		if ( false !== $pos ) {
			$hash = substr( $base, $pos );
			$base = substr( $base, 0, $pos );
		}
		return $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . implode( '&', $query ) . $hash;
	}

	/**
	 * A URL as people read it (percent-encoded Persian slugs decoded once).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function readable_url( $url ) {
		$decoded = rawurldecode( (string) $url );
		return ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $decoded, 'UTF-8' ) ) ? (string) $url : $decoded;
	}

	/**
	 * Latin digits → Persian.
	 *
	 * @param string|int|float $s Text.
	 * @return string
	 */
	public static function fa( $s ) {
		return strtr( (string) $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
	}

	/**
	 * Number with Persian digits and separators ("۱۲۳٬۴۵۶").
	 *
	 * @param float|int|null $n        Number.
	 * @param int            $decimals Decimals.
	 * @return string
	 */
	public static function num( $n, $decimals = 0 ) {
		if ( null === $n ) {
			return '—';
		}
		$s = number_format( (float) $n, max( 0, (int) $decimals ), '٫', '٬' );
		if ( $decimals > 0 && false !== strpos( $s, '٫' ) ) {
			$s = rtrim( rtrim( $s, '0' ), '٫' );
		}
		return self::fa( $s );
	}

	/**
	 * Percent as people write it: "۲۰٪", "۱۲٫۵٪".
	 *
	 * @param float|null $fraction 0.2 = 20%.
	 * @return string
	 */
	public static function percent( $fraction ) {
		return null === $fraction ? '—' : self::num( $fraction * 100, 1 ) . '٪';
	}

	/**
	 * Site wall-clock "Y-m-d H:i" (from Clock::local) → "۶ آذر ۱۴۰۵، ساعت ۰۸:۳۰".
	 *
	 * @param string|null $local    Local Gregorian date-time.
	 * @param bool        $with_time Include the time.
	 * @return string
	 */
	public static function jalali( $local, $with_time = true ) {
		if ( null === $local || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', (string) $local, $m ) ) {
			return '—';
		}
		list( $jy, $jm, $jd ) = Clock::gregorian_to_jalali( (int) $m[1], (int) $m[2], (int) $m[3] );
		$out                  = $jd . ' ' . self::MONTHS[ $jm - 1 ] . ' ' . $jy;
		if ( $with_time && isset( $m[4] ) && '' !== $m[4] ) {
			$out .= '، ساعت ' . $m[4] . ':' . $m[5];
		}
		return self::fa( $out );
	}

	/**
	 * File name for a download: campaign name + date, safe on every OS.
	 *
	 * @param string $name  Campaign name.
	 * @param string $date  Suffix (e.g. Jalali date "1405-09-06").
	 * @param string $ext   Extension.
	 * @return string
	 */
	public static function file_name( $name, $date, $ext ) {
		$name = (string) preg_replace( '/[\x00-\x1F\x7F\/\\\\:*?"<>|#%{}^~\[\]`;,\'&=+$@!]+/u', ' ', self::plain( $name ) );
		$name = trim( (string) preg_replace( '/[\s\-]+/u', '-', $name ), '-.' );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 60, 'UTF-8' );
		}
		$parts = array_filter( array( 'گزارش', $name, (string) $date ), 'strlen' );
		return implode( '-', $parts ) . '.' . $ext;
	}
}
