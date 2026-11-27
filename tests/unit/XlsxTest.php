<?php
// phpcs:ignoreFile

use SmartBulkDiscount\Xlsx;
use SmartBulkDiscount\Zip;
use PHPUnit\Framework\TestCase;

/**
 * The workbook is checked with an independent ZIP reader (below) and DOM, not with the
 * writer's own code, so a format bug cannot hide itself.
 */
class XlsxTest extends TestCase {

	/**
	 * Minimal ZIP reader: central directory → name => contents (verifies CRC and sizes).
	 */
	public static function unzip( $bytes ) {
		$eocd = strrpos( $bytes, "PK\x05\x06" );
		self::assertNotFalse( $eocd, 'end of central directory' );
		$e      = unpack( 'Vsig/vdisk/vcd_disk/vn_disk/vn/Vsize/Voffset/vclen', substr( $bytes, $eocd, 22 ) );
		$pos    = $e['offset'];
		$files  = array();
		for ( $i = 0; $i < $e['n']; $i++ ) {
			$h = unpack( 'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr( $bytes, $pos, 46 ) );
			self::assertSame( 0x02014b50, $h['sig'] );
			$name = substr( $bytes, $pos + 46, $h['nlen'] );
			$pos += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];

			$l = unpack( 'Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr( $bytes, $h['offset'], 30 ) );
			self::assertSame( 0x04034b50, $l['sig'] );
			self::assertSame( $name, substr( $bytes, $h['offset'] + 30, $l['nlen'] ) );
			self::assertSame( $h['crc'], $l['crc'] );
			$raw  = substr( $bytes, $h['offset'] + 30 + $l['nlen'] + $l['xlen'], $h['csize'] );
			$data = 8 === $h['method'] ? gzinflate( $raw ) : $raw;
			self::assertSame( $h['size'], strlen( $data ), $name );
			self::assertSame( sprintf( '%08x', $h['crc'] ), hash( 'crc32b', $data ), $name . ' crc' );
			$files[ $name ] = $data;
		}
		return $files;
	}

	private function build( callable $fill, array $opts = array() ) {
		$x = new Xlsx( $opts );
		$fill( $x );
		$out = fopen( 'php://temp', 'w+b' );
		$x->save( $out, 1764230400 );
		rewind( $out );
		$bytes = stream_get_contents( $out );
		fclose( $out );
		return self::unzip( $bytes );
	}

	private function dom( $xml ) {
		$d = new DOMDocument();
		$this->assertTrue( @$d->loadXML( $xml ), 'well-formed XML' );
		$x = new DOMXPath( $d );
		$x->registerNamespace( 's', Xlsx::NS_MAIN );
		$x->registerNamespace( 'r', Xlsx::NS_REL );
		$x->registerNamespace( 'p', Xlsx::NS_PKG_REL );
		return $x;
	}

	public function test_column_letters() {
		$this->assertSame( 'A', Xlsx::column_letter( 0 ) );
		$this->assertSame( 'Z', Xlsx::column_letter( 25 ) );
		$this->assertSame( 'AA', Xlsx::column_letter( 26 ) );
		$this->assertSame( 'AZ', Xlsx::column_letter( 51 ) );
		$this->assertSame( 'BA', Xlsx::column_letter( 52 ) );
		$this->assertSame( 'ZZ', Xlsx::column_letter( 701 ) );
		$this->assertSame( 'AAA', Xlsx::column_letter( 702 ) );
	}

	public function test_package_has_every_part_and_all_xml_is_well_formed() {
		$files = $this->build(
			function ( Xlsx $x ) {
				$x->add_sheet( 'محصولات', array( array( 'title' => 'نام', 'type' => Xlsx::TEXT ) ) );
				$x->add_row( array( 'کفش' ) );
				$x->add_sheet( 'خلاصه', array( array( 'type' => 'label' ), array( 'type' => Xlsx::TEXT ) ), array( 'header' => false ) );
				$x->add_row( array( 'تخفیف', '۲۰٪' ) );
			}
		);
		foreach ( array( '[Content_Types].xml', '_rels/.rels', 'docProps/core.xml', 'docProps/app.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml' ) as $part ) {
			$this->assertArrayHasKey( $part, $files, $part );
			$this->dom( $files[ $part ] );
		}
		$this->assertStringStartsWith( '[Content_Types].xml', array_keys( $files )[0] );
		$wb = $this->dom( $files['xl/workbook.xml'] );
		$this->assertSame( 'محصولات', $wb->evaluate( 'string(//s:sheet[1]/@name)' ) );
		$this->assertSame( 'خلاصه', $wb->evaluate( 'string(//s:sheet[2]/@name)' ) );
		// Content types list both sheets.
		$this->assertStringContainsString( '/xl/worksheets/sheet2.xml', $files['[Content_Types].xml'] );
	}

	public function test_cells_are_typed_rtl_and_formula_safe() {
		$files = $this->build(
			function ( Xlsx $x ) {
				$x->add_sheet(
					'P',
					array(
						array( 'title' => 'ردیف', 'type' => Xlsx::INT ),
						array( 'title' => 'نام', 'type' => Xlsx::TEXT ),
						array( 'title' => 'قیمت', 'type' => Xlsx::MONEY ),
						array( 'title' => '٪', 'type' => Xlsx::PERCENT ),
					),
					array( 'freeze' => true, 'filter' => true )
				);
				$x->add_row( array( 1, '=HYPERLINK("http://evil")', 1250000, 0.2 ) );
				$x->add_row( array( 2, "A & B <i>\x01\x0B", null, 'not a number' ) );
				$x->add_row( array( 3, 'x', 99.5, 0.125 ) );
			},
			array( 'decimals' => 2 )
		);
		$s = $this->dom( $files['xl/worksheets/sheet1.xml'] );
		$this->assertSame( '1', $s->evaluate( 'string(//s:sheetView/@rightToLeft)' ) );
		$this->assertSame( 'frozen', $s->evaluate( 'string(//s:pane/@state)' ) );
		$this->assertSame( 'A1:D4', $s->evaluate( 'string(//s:autoFilter/@ref)' ) );
		$this->assertSame( 'A1:D4', $s->evaluate( 'string(//s:dimension/@ref)' ) );

		// Header row = styled text.
		$this->assertSame( '1', $s->evaluate( 'string(//s:c[@r="A1"]/@s)' ) );
		// Numbers are numbers.
		$this->assertSame( '1250000', $s->evaluate( 'string(//s:c[@r="C2"]/s:v)' ) );
		$this->assertSame( '0.2', $s->evaluate( 'string(//s:c[@r="D2"]/s:v)' ) );
		$this->assertSame( '99.5', $s->evaluate( 'string(//s:c[@r="C4"]/s:v)' ) );
		// A product named like a formula stays text: no <f> anywhere.
		$this->assertSame( 0.0, $s->evaluate( 'count(//s:f)' ) );
		$this->assertSame( '=HYPERLINK("http://evil")', $s->evaluate( 'string(//s:c[@r="B2"]/s:is/s:t)' ) );
		// Control characters dropped, markup escaped.
		$this->assertSame( 'A & B <i>', $s->evaluate( 'string(//s:c[@r="B3"]/s:is/s:t)' ) );
		// Empty and non-numeric values become empty cells.
		$this->assertSame( 0.0, $s->evaluate( 'count(//s:c[@r="C3"])' ) );
		$this->assertSame( 0.0, $s->evaluate( 'count(//s:c[@r="D3"])' ) );

		$st = $this->dom( $files['xl/styles.xml'] );
		$this->assertSame( '#,##0.00', $st->evaluate( 'string(//s:numFmt[@numFmtId="164"]/@formatCode)' ) );
		$this->assertSame( '164', $st->evaluate( 'string(//s:cellXfs/s:xf[' . ( Xlsx::S_MONEY + 1 ) . ']/@numFmtId)' ) );

		$wb = $this->dom( $files['xl/workbook.xml'] );
		$this->assertSame( "'P'!\$A\$1:\$D\$4", $wb->evaluate( 'string(//s:definedName)' ) );
	}

	public function test_money_without_decimals_uses_thousands_format() {
		$files = $this->build(
			function ( Xlsx $x ) {
				$x->add_sheet( 'P', array( array( 'title' => 'قیمت', 'type' => Xlsx::MONEY ) ) );
				$x->add_row( array( 1000 ) );
			}
		);
		$st = $this->dom( $files['xl/styles.xml'] );
		$this->assertSame( '3', $st->evaluate( 'string(//s:cellXfs/s:xf[' . ( Xlsx::S_MONEY + 1 ) . ']/@numFmtId)' ) );
	}

	public function test_links_are_real_hyperlinks_with_relationships() {
		$url   = 'https://shop.test/product/%d8%b3%d8%a8%d8%b2/?attribute_pa_size=%25d8%25a8&x=1';
		$files = $this->build(
			function ( Xlsx $x ) use ( $url ) {
				$x->add_sheet( 'P', array( array( 'title' => 'لینک', 'type' => Xlsx::LINK ) ) );
				$x->add_row( array( array( 'url' => $url, 'text' => 'https://shop.test/product/سبز/' ) ) );
				$x->add_row( array( array( 'url' => 'javascript:alert(1)', 'text' => 'bad' ) ) );
				$x->add_row( array( null ) );
			}
		);
		$s = $this->dom( $files['xl/worksheets/sheet1.xml'] );
		$this->assertSame( 1.0, $s->evaluate( 'count(//s:hyperlink)' ) );
		$this->assertSame( 'A2', $s->evaluate( 'string(//s:hyperlink/@ref)' ) );
		$rid = $s->evaluate( 'string(//s:hyperlink/@r:id)' );
		$this->assertSame( 'https://shop.test/product/سبز/', $s->evaluate( 'string(//s:c[@r="A2"]/s:is/s:t)' ) );
		// A non-http "link" is written as plain text, never as a hyperlink.
		$this->assertSame( 'bad', $s->evaluate( 'string(//s:c[@r="A3"]/s:is/s:t)' ) );

		$this->assertArrayHasKey( 'xl/worksheets/_rels/sheet1.xml.rels', $files );
		$r = $this->dom( $files['xl/worksheets/_rels/sheet1.xml.rels'] );
		$this->assertSame( $url, $r->evaluate( 'string(//p:Relationship[@Id="' . $rid . '"]/@Target)' ) );
		$this->assertSame( 'External', $r->evaluate( 'string(//p:Relationship[@Id="' . $rid . '"]/@TargetMode)' ) );
	}

	public function test_sheet_names_follow_excel_rules() {
		$files = $this->build(
			function ( Xlsx $x ) {
				$x->add_sheet( 'a/b:c*d?[e]', array() );
				$x->add_sheet( str_repeat( 'ب', 40 ), array() );
				$x->add_sheet( 'a b c d e', array() );
				$x->add_sheet( '', array() );
			}
		);
		$wb    = $this->dom( $files['xl/workbook.xml'] );
		$names = array();
		foreach ( $wb->query( '//s:sheet/@name' ) as $n ) {
			$names[] = $n->nodeValue;
		}
		$this->assertSame( 'a b c d e', $names[0] );
		$this->assertSame( 31, mb_strlen( $names[1] ) );
		$this->assertNotSame( $names[0], $names[2], 'unique' );
		$this->assertSame( 'Sheet4', $names[3] );
	}

	public function test_large_sheet_streams_and_stays_valid() {
		$files = $this->build(
			function ( Xlsx $x ) {
				$x->add_sheet( 'P', array( array( 'title' => 'n', 'type' => Xlsx::INT ), array( 'title' => 'name', 'type' => Xlsx::TEXT ), array( 'title' => 'u', 'type' => Xlsx::LINK ) ) );
				for ( $i = 1; $i <= 5000; $i++ ) {
					$x->add_row( array( $i, 'محصول شمارهٔ ' . $i, array( 'url' => 'https://shop.test/?p=' . $i ) ) );
				}
			}
		);
		$s = $this->dom( $files['xl/worksheets/sheet1.xml'] );
		$this->assertSame( 5001.0, $s->evaluate( 'count(//s:row)' ) );
		$this->assertSame( 5000.0, $s->evaluate( 'count(//s:hyperlink)' ) );
		$this->assertSame( '5000', $s->evaluate( 'string(//s:c[@r="A5001"]/s:v)' ) );
	}

	public function test_zip_stored_fallback_is_readable() {
		$out = fopen( 'php://temp', 'w+b' );
		$zip = new Zip( $out, 1764230400, false );
		$zip->add_string( 'a.txt', str_repeat( 'سلام ', 1000 ) );
		$zip->add_string( 'empty.txt', '' );
		$zip->finish();
		rewind( $out );
		$files = self::unzip( stream_get_contents( $out ) );
		$this->assertSame( str_repeat( 'سلام ', 1000 ), $files['a.txt'] );
		$this->assertSame( '', $files['empty.txt'] );
	}
}
