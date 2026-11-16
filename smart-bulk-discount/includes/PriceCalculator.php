<?php
/**
 * Pure price math. No WordPress calls — unit tested in isolation.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the discounted price and decides whether to apply it.
 */
final class PriceCalculator {

	const TYPE_PERCENT = 'percent';
	const TYPE_FIXED   = 'fixed';

	/** Existing (foreign) sale price policies. */
	const POLICY_BEST     = 'best';      // keep whichever gives the customer the lower price.
	const POLICY_OVERRIDE = 'override';  // always use ours, restore theirs at the end.
	const POLICY_KEEP     = 'keep';      // never touch products that already have a sale.

	const ALLOWED_STEPS = array( 0, 10, 100, 500, 1000, 5000, 10000 );

	/**
	 * Clamp a rounding step to the allowed list.
	 *
	 * @param mixed $step Raw.
	 * @return int
	 */
	public static function sanitize_step( $step ) {
		$step = (int) $step;
		return in_array( $step, self::ALLOWED_STEPS, true ) ? $step : 0;
	}

	/**
	 * Validate discount input. Returns an error message or '' when valid.
	 *
	 * @param string $type  percent|fixed.
	 * @param float  $value Amount.
	 * @return string
	 */
	public static function validate( $type, $value ) {
		if ( self::TYPE_PERCENT !== $type && self::TYPE_FIXED !== $type ) {
			return 'invalid_type';
		}
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return 'value_must_be_positive';
		}
		if ( self::TYPE_PERCENT === $type && (float) $value >= 100 ) {
			return 'percent_must_be_below_100';
		}
		return '';
	}

	/**
	 * Discounted price, or null when the discount cannot be applied to this price.
	 *
	 * @param float $regular  Regular price.
	 * @param string $type    percent|fixed.
	 * @param float $value    Discount amount.
	 * @param array $opts     round_step (int), decimals (int), min_price (float).
	 * @return float|null
	 */
	public static function calculate( $regular, $type, $value, array $opts = array() ) {
		$regular  = (float) $regular;
		$value    = (float) $value;
		$step     = isset( $opts['round_step'] ) ? self::sanitize_step( $opts['round_step'] ) : 0;
		$decimals = isset( $opts['decimals'] ) ? max( 0, (int) $opts['decimals'] ) : 0;
		$min      = isset( $opts['min_price'] ) ? max( 0.0, (float) $opts['min_price'] ) : 0.0;

		if ( $regular <= 0 || '' !== self::validate( $type, $value ) ) {
			return null;
		}

		if ( self::TYPE_PERCENT === $type ) {
			$price = $regular * ( 100 - $value ) / 100;
		} else {
			$price = $regular - $value;
		}

		if ( $step > 0 ) {
			// Round down to a "nice" number: the customer never pays more than the advertised discount.
			$price = floor( ( $price + 1e-9 ) / $step ) * $step;
		} else {
			$factor = pow( 10, $decimals );
			$price  = floor( $price * $factor + 1e-9 ) / $factor;
		}

		if ( $price <= 0 || $price < $min ) {
			return null;
		}
		if ( $price >= $regular ) {
			// Rounding/too-small discount produced no real discount.
			return null;
		}
		return $price;
	}

	/**
	 * Decide what to do given an existing sale price that is NOT ours.
	 *
	 * @param float|null $existing_sale Existing sale price (null = none).
	 * @param float      $regular       Regular price.
	 * @param float      $new_price     Our computed price.
	 * @param string     $policy        best|override|keep.
	 * @return array{apply:bool,reason:string}
	 */
	public static function decide( $existing_sale, $regular, $new_price, $policy ) {
		$has_sale = null !== $existing_sale && $existing_sale > 0 && $existing_sale < $regular;
		if ( ! $has_sale ) {
			return array(
				'apply'  => true,
				'reason' => '',
			);
		}
		if ( self::POLICY_KEEP === $policy ) {
			return array(
				'apply'  => false,
				'reason' => 'has_own_sale',
			);
		}
		if ( self::POLICY_BEST === $policy && $existing_sale <= $new_price ) {
			return array(
				'apply'  => false,
				'reason' => 'own_sale_is_better',
			);
		}
		return array(
			'apply'  => true,
			'reason' => '',
		);
	}

	/**
	 * Numeric equality for prices stored as strings.
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return bool
	 */
	public static function same( $a, $b ) {
		if ( '' === (string) $a || null === $a ) {
			return '' === (string) $b || null === $b;
		}
		if ( '' === (string) $b || null === $b ) {
			return false;
		}
		return abs( (float) $a - (float) $b ) < 0.00001;
	}
}
