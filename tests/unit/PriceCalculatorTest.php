<?php
// phpcs:ignoreFile

use SmartBulkDiscount\PriceCalculator as P;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase {

	public function test_percent_discount() {
		$this->assertSame( 80000.0, P::calculate( 100000, 'percent', 20 ) );
		$this->assertSame( 75000.0, P::calculate( 100000, 'percent', 25 ) );
	}

	public function test_fixed_discount() {
		$this->assertSame( 85000.0, P::calculate( 100000, 'fixed', 15000 ) );
	}

	public function test_fixed_larger_than_price_is_rejected() {
		$this->assertNull( P::calculate( 10000, 'fixed', 10000 ) );
		$this->assertNull( P::calculate( 10000, 'fixed', 25000 ) );
	}

	public function test_invalid_inputs() {
		$this->assertNull( P::calculate( 0, 'percent', 10 ) );
		$this->assertNull( P::calculate( -5, 'percent', 10 ) );
		$this->assertNull( P::calculate( 1000, 'percent', 100 ) );
		$this->assertNull( P::calculate( 1000, 'percent', 0 ) );
		$this->assertNull( P::calculate( 1000, 'bogus', 10 ) );
	}

	public function test_rounding_down_to_step() {
		// 123,456 * 0.83 = 102,468.48 → floor to 1000 = 102,000.
		$this->assertSame( 102000.0, P::calculate( 123456, 'percent', 17, array( 'round_step' => 1000 ) ) );
		$this->assertSame( 102400.0, P::calculate( 123456, 'percent', 17, array( 'round_step' => 100 ) ) );
		$this->assertSame( 102460.0, P::calculate( 123456, 'percent', 17, array( 'round_step' => 10 ) ) );
	}

	public function test_rounding_never_exceeds_regular() {
		// 1% off 1000 = 990, step 1000 → 0 → rejected (no real price).
		$this->assertNull( P::calculate( 1000, 'percent', 1, array( 'round_step' => 1000 ) ) );
		// 0.4% off 10,000 rounded to 100 → 9,900 (still a discount).
		$this->assertSame( 9900.0, P::calculate( 10000, 'percent', 0.4, array( 'round_step' => 100 ) ) );
	}

	public function test_decimals_without_step() {
		$this->assertSame( 8.99, P::calculate( 9.99, 'percent', 10, array( 'decimals' => 2 ) ) );
		$this->assertSame( 8.0, P::calculate( 9.99, 'percent', 10, array( 'decimals' => 0 ) ) );
	}

	public function test_float_precision_does_not_lose_a_unit() {
		// 0.7 * 100000 is 69999.99999 in float math; must stay 70000.
		$this->assertSame( 70000.0, P::calculate( 100000, 'percent', 30 ) );
		$this->assertSame( 70000.0, P::calculate( 100000, 'percent', 30, array( 'round_step' => 1000 ) ) );
	}

	public function test_min_price_floor() {
		$this->assertNull( P::calculate( 50000, 'percent', 90, array( 'min_price' => 10000 ) ) );
		$this->assertSame( 10000.0, P::calculate( 50000, 'percent', 80, array( 'min_price' => 10000 ) ) );
	}

	public function test_unknown_step_is_ignored() {
		$this->assertSame( 0, P::sanitize_step( 333 ) );
		$this->assertSame( 1000, P::sanitize_step( '1000' ) );
	}

	public function test_validate_messages() {
		$this->assertSame( '', P::validate( 'percent', 10 ) );
		$this->assertSame( 'percent_must_be_below_100', P::validate( 'percent', 100 ) );
		$this->assertSame( 'value_must_be_positive', P::validate( 'fixed', 0 ) );
		$this->assertSame( 'value_must_be_positive', P::validate( 'fixed', 'abc' ) );
		$this->assertSame( 'invalid_type', P::validate( 'x', 10 ) );
	}

	public function test_policy_best_keeps_better_existing_sale() {
		$d = P::decide( 70000, 100000, 80000, P::POLICY_BEST );
		$this->assertFalse( $d['apply'] );
		$this->assertSame( 'own_sale_is_better', $d['reason'] );

		$d = P::decide( 90000, 100000, 80000, P::POLICY_BEST );
		$this->assertTrue( $d['apply'] );
	}

	public function test_policy_override_and_keep() {
		$this->assertTrue( P::decide( 70000, 100000, 80000, P::POLICY_OVERRIDE )['apply'] );
		$d = P::decide( 90000, 100000, 80000, P::POLICY_KEEP );
		$this->assertFalse( $d['apply'] );
		$this->assertSame( 'has_own_sale', $d['reason'] );
	}

	public function test_no_existing_sale_always_applies() {
		foreach ( array( P::POLICY_BEST, P::POLICY_OVERRIDE, P::POLICY_KEEP ) as $policy ) {
			$this->assertTrue( P::decide( null, 100000, 80000, $policy )['apply'] );
			// A "sale" not below regular is not a real sale.
			$this->assertTrue( P::decide( 100000, 100000, 80000, $policy )['apply'] );
		}
	}

	public function test_same() {
		$this->assertTrue( P::same( '80000', 80000.0 ) );
		$this->assertTrue( P::same( '', null ) );
		$this->assertFalse( P::same( '', '0' ) );
		$this->assertFalse( P::same( '80000', '80001' ) );
	}
}
