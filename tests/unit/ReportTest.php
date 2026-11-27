<?php
// phpcs:ignoreFile

use SmartBulkDiscount\Report as R;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase {

	private function item( array $over = array() ) {
		return (object) array_merge(
			array(
				'id'           => 1,
				'product_id'   => 10,
				'parent_id'    => 0,
				'status'       => 'applied',
				'orig_regular' => '1000000',
				'orig_sale'    => '',
				'new_sale'     => '800000',
				'note'         => '',
				'post_title'   => 'کفش ورزشی',
				'parent_title' => null,
				'sku'          => 'SH-1',
				'parent_sku'   => null,
			),
			$over
		);
	}

	public function test_applied_row_has_previous_and_new_price_and_discount() {
		$r = R::row( $this->item(), 'https://shop.test/p/1/' );
		$this->assertSame( 10, $r['product_id'] );
		$this->assertSame( 'کفش ورزشی', $r['name'] );
		$this->assertSame( 'SH-1', $r['sku'] );
		$this->assertSame( 'https://shop.test/p/1/', $r['url'] );
		$this->assertSame( 1000000.0, $r['regular'] );
		$this->assertSame( 800000.0, $r['new_price'] );
		$this->assertSame( 200000.0, $r['saving'] );
		$this->assertSame( 0.2, $r['percent'] );
		$this->assertNull( $r['old_sale'] );
		$this->assertSame( 'اعمال شد', $r['status_fa'] );
	}

	public function test_reverted_rows_keep_the_price_the_campaign_used() {
		$r = R::row( $this->item( array( 'status' => 'reverted' ) ) );
		$this->assertSame( 800000.0, $r['new_price'] );
		$this->assertSame( 'برگشت', $r['status_fa'] );
	}

	public function test_skipped_or_failed_rows_show_no_new_price() {
		foreach ( array( 'skipped', 'failed', 'cancelled', 'pending' ) as $st ) {
			$r = R::row( $this->item( array( 'status' => $st, 'note' => 'has_own_sale' ) ) );
			$this->assertNull( $r['new_price'], $st );
			$this->assertNull( $r['percent'], $st );
		}
		$r = R::row( $this->item( array( 'status' => 'skipped', 'orig_regular' => null, 'new_sale' => null, 'note' => 'out_of_stock' ) ) );
		$this->assertNull( $r['regular'] );
		$this->assertSame( 'ناموجود است', $r['note'] );
	}

	public function test_untouched_items_show_their_current_price_as_previous() {
		// Skipped before any backup: the product was never changed, so today's price is the previous one.
		$r = R::row( $this->item( array( 'status' => 'skipped', 'orig_regular' => null, 'orig_sale' => null, 'new_sale' => null, 'note' => 'has_own_sale', 'cur_regular' => '500000', 'cur_sale' => '450000' ) ) );
		$this->assertSame( 500000.0, $r['regular'] );
		$this->assertSame( 450000.0, $r['old_sale'] );
		$this->assertNull( $r['new_price'] );
		// With a backup, the backup wins over the (discounted) current price.
		$r = R::row( $this->item( array( 'cur_regular' => '1', 'cur_sale' => '800000' ) ) );
		$this->assertSame( 1000000.0, $r['regular'] );
		$this->assertNull( $r['old_sale'] );
	}

	public function test_previous_sale_only_when_it_was_a_real_sale() {
		$this->assertSame( 900000.0, R::row( $this->item( array( 'orig_sale' => '900000' ) ) )['old_sale'] );
		$this->assertNull( R::row( $this->item( array( 'orig_sale' => '1000000' ) ) )['old_sale'] );
		$this->assertNull( R::row( $this->item( array( 'orig_sale' => '0' ) ) )['old_sale'] );
		$this->assertNull( R::row( $this->item( array( 'orig_sale' => 'abc' ) ) )['old_sale'] );
	}

	public function test_variation_name_and_parent_sku_fallback() {
		$r = R::row( $this->item( array( 'product_id' => 11, 'parent_id' => 10, 'post_title' => 'قرمز', 'parent_title' => 'تی‌شرت &amp; شلوار', 'sku' => '', 'parent_sku' => 'TS' ) ) );
		$this->assertSame( 'تی‌شرت & شلوار — قرمز', $r['name'] );
		$this->assertSame( 'TS', $r['sku'] );
		$this->assertSame( 10, $r['parent_id'] );
		// WooCommerce's own variation titles already contain the parent's.
		$this->assertSame( 'تی‌شرت - قرمز', R::display_name( 'تی‌شرت - قرمز', 'تی‌شرت', 11, 10 ) );
		$this->assertSame( '#11', R::display_name( '', '', 11, 0 ) );
	}

	public function test_variation_titled_like_its_parent_gets_its_attributes() {
		$label = R::variant_label( array( 'attribute_size' => 'XL', 'attribute_pa_color' => '%d8%b3%d8%a8%d8%b2-%d8%aa%db%8c%d8%b1%d9%87', 'attribute_pa_any' => '' ) );
		$this->assertSame( 'سبز تیره، XL', $label );
		$r = R::row( $this->item( array( 'product_id' => 11, 'parent_id' => 10, 'post_title' => 'کفش', 'parent_title' => 'کفش' ) ), '', $label );
		$this->assertSame( 'کفش — سبز تیره، XL', $r['name'] );
		// A WooCommerce-style title already says which variation it is: left alone.
		$this->assertSame( 'کفش - XL', R::display_name( 'کفش - XL', 'کفش', 11, 10, 'XL' ) );
		// Simple products never get a suffix.
		$this->assertSame( 'کفش', R::display_name( 'کفش', '', 10, 0, 'XL' ) );
	}

	public function test_short_url_for_print() {
		$this->assertSame( 'shop.test/product/تی/', R::short_url( 'https://www.shop.test/product/%d8%aa%db%8c/?attribute_size=M' ) );
		$this->assertSame( 'shop.test/?product=t', R::short_url( 'http://shop.test/?product=t&attribute_size=M' ) );
		$this->assertSame( 'shop.test/?p=12', R::short_url( 'https://shop.test/?p=12' ) );
	}

	public function test_variation_url_matches_woocommerce() {
		$base = 'https://shop.test/product/%d8%aa%db%8c/';
		$this->assertSame(
			$base . '?attribute_pa_color=%25d9%2582%25d8%25b1%25d9%2585%25d8%25b2&attribute_size=XL+Big',
			R::variation_url( $base, array( 'attribute_size' => 'XL Big', 'attribute_pa_color' => '%d9%82%d8%b1%d9%85%d8%b2' ) )
		);
		// "Any" attributes (empty) are left out; plain permalinks get "&".
		$this->assertSame( 'https://shop.test/?product=t', R::variation_url( 'https://shop.test/?product=t', array( 'attribute_size' => '' ) ) );
		$this->assertSame( 'https://shop.test/?product=t&attribute_size=M', R::variation_url( 'https://shop.test/?product=t', array( 'attribute_size' => 'M' ) ) );
		$this->assertSame( '', R::variation_url( '', array( 'attribute_size' => 'M' ) ) );
	}

	public function test_readable_url_decodes_persian_slugs_once() {
		$this->assertSame( 'https://shop.test/product/تی/', R::readable_url( 'https://shop.test/product/%d8%aa%db%8c/' ) );
		$this->assertSame( 'https://shop.test/%ff', R::readable_url( 'https://shop.test/%ff' ) );
	}

	public function test_persian_numbers_and_percent() {
		$this->assertSame( '۱٬۲۵۰٬۰۰۰', R::num( 1250000 ) );
		$this->assertSame( '۹۹٫۵', R::num( 99.5, 2 ) );
		$this->assertSame( '۱۰۰', R::num( 100.0, 2 ) );
		$this->assertSame( '۲۰٪', R::percent( 0.2 ) );
		$this->assertSame( '۱۲٫۵٪', R::percent( 0.125 ) );
		$this->assertSame( '—', R::num( null ) );
	}

	public function test_jalali_dates() {
		$this->assertSame( '۶ آذر ۱۴۰۵، ساعت ۰۸:۳۰', R::jalali( '2026-11-27 08:30' ) );
		$this->assertSame( '۱ فروردین ۱۴۰۵', R::jalali( '2026-03-21 00:00', false ) );
		$this->assertSame( '—', R::jalali( null ) );
	}

	public function test_file_name_is_safe() {
		$this->assertSame( 'گزارش-تخفیف-یلدا-1405-09-06.xlsx', R::file_name( 'تخفیف  یلدا', '1405-09-06', 'xlsx' ) );
		$this->assertSame( 'گزارش-a-b-c-1405-09-06.pdf', R::file_name( 'a/b\\c:*?"<>|', '1405-09-06', 'pdf' ) );
		$this->assertSame( 'گزارش-1405-09-06.pdf', R::file_name( '///', '1405-09-06', 'pdf' ) );
	}
}
