<?php
/**
 * Minimal Excel (.xlsx) writer: right-to-left sheets, number formats, real hyperlinks.
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Streams rows into temporary files (constant memory per row) and packs a valid
 * Office Open XML workbook with Zip. Pure PHP 7.4, no WordPress: unit tested.
 *
 * Column types: text, int, money, percent (value is a fraction: 0.2 = 20%), link
 * (value is array( 'url' => …, 'text' => … )).
 */
final class Xlsx {

	const TEXT    = 'text';
	const INT     = 'int';
	const MONEY   = 'money';
	const PERCENT = 'percent';
	const LINK    = 'link';

	/** Excel's per-sheet hyperlink limit is 65 530; its URL limit is 2 079 characters. */
	const MAX_LINKS   = 65000;
	const MAX_URL     = 2000;
	const MAX_TEXT    = 32000;
	const NS_MAIN     = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	const NS_REL      = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	const NS_PKG_REL  = 'http://schemas.openxmlformats.org/package/2006/relationships';

	// Cell style indexes (cellXfs order in styles()).
	const S_DEFAULT = 0;
	const S_HEADER  = 1;
	const S_INT     = 2;
	const S_MONEY   = 3;
	const S_PERCENT = 4;
	const S_LINK    = 5;
	const S_LABEL   = 6;

	/** @var array[] */
	private $sheets = array();

	/** @var int Index of the sheet receiving rows. */
	private $current = -1;

	/** @var int */
	private $decimals;

	/** @var bool */
	private $rtl;

	/** @var string */
	private $title;

	/** @var string */
	private $creator;

	/**
	 * Constructor.
	 *
	 * @param array $opts decimals (money), rtl, title, creator.
	 */
	public function __construct( array $opts = array() ) {
		$this->decimals = isset( $opts['decimals'] ) ? max( 0, min( 6, (int) $opts['decimals'] ) ) : 0;
		$this->rtl      = isset( $opts['rtl'] ) ? (bool) $opts['rtl'] : true;
		$this->title    = isset( $opts['title'] ) ? (string) $opts['title'] : '';
		$this->creator  = isset( $opts['creator'] ) ? (string) $opts['creator'] : '';
	}

	/**
	 * Start a new sheet. Its header row is written at once.
	 *
	 * @param string $name    Sheet name (cleaned to Excel's rules).
	 * @param array  $columns Each: title, type, width.
	 * @param array  $opts    header (bool, default true), freeze (bool), filter (bool).
	 */
	public function add_sheet( $name, array $columns, array $opts = array() ) {
		$this->sheets[] = array(
			'name'    => $this->sheet_name( $name, count( $this->sheets ) + 1 ),
			'columns' => array_values( $columns ),
			'header'  => ! isset( $opts['header'] ) || $opts['header'],
			'freeze'  => ! empty( $opts['freeze'] ),
			'filter'  => ! empty( $opts['filter'] ),
			'rows'    => fopen( 'php://temp/maxmemory:2097152', 'w+b' ),
			'links'   => fopen( 'php://temp/maxmemory:1048576', 'w+b' ),
			'rels'    => fopen( 'php://temp/maxmemory:1048576', 'w+b' ),
			'count'   => 0,
			'nlinks'  => 0,
		);
		$this->current = count( $this->sheets ) - 1;
		if ( $this->sheets[ $this->current ]['header'] ) {
			$titles = array();
			foreach ( $this->sheets[ $this->current ]['columns'] as $col ) {
				$titles[] = isset( $col['title'] ) ? $col['title'] : '';
			}
			$this->write_row( $titles, true );
		}
	}

	/**
	 * Append a row to the current sheet. Cells follow the column order.
	 *
	 * @param array $cells Values.
	 */
	public function add_row( array $cells ) {
		if ( $this->current < 0 ) {
			$this->add_sheet( 'Sheet1', array() );
		}
		$this->write_row( array_values( $cells ), false );
	}

	/**
	 * Rows written to the current sheet (header included).
	 *
	 * @return int
	 */
	public function row_count() {
		return $this->current < 0 ? 0 : $this->sheets[ $this->current ]['count'];
	}

	/**
	 * Write the finished workbook to a stream and release the temporary files.
	 *
	 * @param resource $out   Writable stream.
	 * @param int|null $mtime Timestamp stored in the archive and document properties.
	 */
	public function save( $out, $mtime = null ) {
		if ( empty( $this->sheets ) ) {
			$this->add_sheet( 'Sheet1', array() );
		}
		$mtime = null === $mtime ? time() : (int) $mtime;
		$zip   = new Zip( $out, $mtime );
		$n     = count( $this->sheets );

		$zip->add_string( '[Content_Types].xml', $this->content_types( $n ) );
		$zip->add_string(
			'_rels/.rels',
			$this->rels(
				array(
					array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'xl/workbook.xml' ),
					array( 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml' ),
					array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties', 'docProps/app.xml' ),
				)
			)
		);
		$zip->add_string( 'docProps/core.xml', $this->core( $mtime ) );
		$zip->add_string( 'docProps/app.xml', $this->app() );
		$zip->add_string( 'xl/workbook.xml', $this->workbook() );
		$wb_rels = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$wb_rels[] = array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet', 'worksheets/sheet' . $i . '.xml' );
		}
		$wb_rels[] = array( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles', 'styles.xml' );
		$zip->add_string( 'xl/_rels/workbook.xml.rels', $this->rels( $wb_rels ) );
		$zip->add_string( 'xl/styles.xml', $this->styles() );

		foreach ( $this->sheets as $i => $sheet ) {
			$xml = $this->sheet_xml( $sheet, 0 === $i );
			$zip->add_stream( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', $xml );
			fclose( $xml );
			if ( $sheet['nlinks'] > 0 ) {
				$rels = fopen( 'php://temp/maxmemory:1048576', 'w+b' );
				fwrite( $rels, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="' . self::NS_PKG_REL . '">' );
				$this->append( $rels, $sheet['rels'] );
				fwrite( $rels, '</Relationships>' );
				$zip->add_stream( 'xl/worksheets/_rels/sheet' . ( $i + 1 ) . '.xml.rels', $rels );
				fclose( $rels );
			}
			fclose( $sheet['rows'] );
			fclose( $sheet['links'] );
			fclose( $sheet['rels'] );
		}
		$zip->finish();
		$this->sheets  = array();
		$this->current = -1;
	}

	/**
	 * "A", "B", … "Z", "AA" for a 0-based column index.
	 *
	 * @param int $index Column.
	 * @return string
	 */
	public static function column_letter( $index ) {
		$index  = (int) $index + 1;
		$letter = '';
		while ( $index > 0 ) {
			$mod    = ( $index - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$index  = intdiv( $index - $mod, 26 );
		}
		return $letter;
	}

	/**
	 * Escape text for XML; drops characters XML 1.0 forbids (they make Excel refuse the file).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function esc( $s ) {
		$s = (string) $s;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}
		$s = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s );
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}

	// ---------------------------------------------------------------------
	// Rows.
	// ---------------------------------------------------------------------

	/**
	 * Serialize one row into the current sheet's temp file.
	 *
	 * @param array $cells  Values.
	 * @param bool  $header Header row.
	 */
	private function write_row( array $cells, $header ) {
		$sheet = &$this->sheets[ $this->current ];
		$r     = $sheet['count'] + 1;
		$xml   = '<row r="' . $r . '">';
		foreach ( $cells as $c => $value ) {
			$ref  = self::column_letter( $c ) . $r;
			$type = $header ? self::TEXT : ( isset( $sheet['columns'][ $c ]['type'] ) ? $sheet['columns'][ $c ]['type'] : self::TEXT );
			$xml .= $this->cell( $sheet, $ref, $type, $value, $header );
		}
		$xml .= '</row>';
		fwrite( $sheet['rows'], $xml );
		++$sheet['count'];
		unset( $sheet );
	}

	/**
	 * One cell's XML ('' for an empty cell).
	 *
	 * @param array  $sheet  Sheet (by reference, for links).
	 * @param string $ref    Cell reference.
	 * @param string $type   Column type.
	 * @param mixed  $value  Value.
	 * @param bool   $header Header row.
	 * @return string
	 */
	private function cell( array &$sheet, $ref, $type, $value, $header ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}
		if ( self::LINK === $type ) {
			$url  = is_array( $value ) && isset( $value['url'] ) ? (string) $value['url'] : (string) $value;
			$text = is_array( $value ) && isset( $value['text'] ) && '' !== (string) $value['text'] ? (string) $value['text'] : $url;
			if ( '' === $url ) {
				return '';
			}
			if ( $sheet['nlinks'] < self::MAX_LINKS && strlen( $url ) <= self::MAX_URL && preg_match( '#^https?://#i', $url ) ) {
				++$sheet['nlinks'];
				$rid = 'rId' . $sheet['nlinks'];
				fwrite( $sheet['links'], '<hyperlink ref="' . $ref . '" r:id="' . $rid . '"/>' );
				fwrite( $sheet['rels'], '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . self::esc( $url ) . '" TargetMode="External"/>' );
				return $this->text_cell( $ref, $text, self::S_LINK );
			}
			return $this->text_cell( $ref, $text, self::S_DEFAULT );
		}
		if ( ! $header && in_array( $type, array( self::INT, self::MONEY, self::PERCENT ), true ) ) {
			if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
				return '';
			}
			$styles = array(
				self::INT     => self::S_INT,
				self::MONEY   => self::S_MONEY,
				self::PERCENT => self::S_PERCENT,
			);
			return '<c r="' . $ref . '" s="' . $styles[ $type ] . '"><v>' . self::number( $value ) . '</v></c>';
		}
		$style = $header ? self::S_HEADER : ( 'label' === $type ? self::S_LABEL : self::S_DEFAULT );
		return $this->text_cell( $ref, (string) $value, $style );
	}

	/**
	 * Inline-string cell (never a formula: a product name such as "=1+1" stays text).
	 *
	 * @param string $ref   Ref.
	 * @param string $text  Text.
	 * @param int    $style Style.
	 * @return string
	 */
	private function text_cell( $ref, $text, $style ) {
		if ( function_exists( 'mb_substr' ) && strlen( $text ) > self::MAX_TEXT ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT, 'UTF-8' );
		}
		return '<c r="' . $ref . '"' . ( $style ? ' s="' . $style . '"' : '' ) . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc( $text ) . '</t></is></c>';
	}

	/**
	 * Locale-independent number literal.
	 *
	 * @param mixed $value Number.
	 * @return string
	 */
	private static function number( $value ) {
		$f = (float) $value;
		if ( floor( $f ) === $f && abs( $f ) < 1e15 ) {
			return sprintf( '%.0f', $f );
		}
		return rtrim( rtrim( sprintf( '%.10F', $f ), '0' ), '.' );
	}

	/**
	 * Copy one temp stream into another.
	 *
	 * @param resource $to   Destination.
	 * @param resource $from Source.
	 */
	private function append( $to, $from ) {
		rewind( $from );
		stream_copy_to_stream( $from, $to );
	}

	// ---------------------------------------------------------------------
	// Package parts.
	// ---------------------------------------------------------------------

	/**
	 * Full worksheet XML as a stream.
	 *
	 * @param array $sheet Sheet.
	 * @param bool  $first First (selected) sheet.
	 * @return resource
	 */
	private function sheet_xml( array $sheet, $first ) {
		$ncols = max( 1, count( $sheet['columns'] ) );
		$last  = self::column_letter( $ncols - 1 ) . max( 1, $sheet['count'] );
		$out   = fopen( 'php://temp/maxmemory:4194304', 'w+b' );

		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml .= '<worksheet xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">';
		$xml .= '<dimension ref="A1:' . $last . '"/>';
		$xml .= '<sheetViews><sheetView workbookViewId="0"' . ( $this->rtl ? ' rightToLeft="1"' : '' ) . ( $first ? ' tabSelected="1"' : '' ) . '>';
		if ( $sheet['freeze'] && $sheet['count'] > 1 ) {
			$xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
		}
		$xml .= '</sheetView></sheetViews>';
		$xml .= '<sheetFormatPr defaultRowHeight="18"/>';
		$cols = '';
		foreach ( $sheet['columns'] as $i => $col ) {
			$w     = isset( $col['width'] ) ? max( 3, min( 120, (float) $col['width'] ) ) : 14;
			$cols .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		if ( '' !== $cols ) {
			$xml .= '<cols>' . $cols . '</cols>';
		}
		$xml .= '<sheetData>';
		fwrite( $out, $xml );
		$this->append( $out, $sheet['rows'] );

		$xml = '</sheetData>';
		if ( $sheet['filter'] && $sheet['count'] > 1 ) {
			$xml .= '<autoFilter ref="A1:' . $last . '"/>';
		}
		fwrite( $out, $xml );
		if ( $sheet['nlinks'] > 0 ) {
			fwrite( $out, '<hyperlinks>' );
			$this->append( $out, $sheet['links'] );
			fwrite( $out, '</hyperlinks>' );
		}
		fwrite( $out, '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" paperSize="9"/></worksheet>' );
		return $out;
	}

	/**
	 * Workbook part.
	 *
	 * @return string
	 */
	private function workbook() {
		$xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml  .= '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '"><bookViews><workbookView/></bookViews><sheets>';
		$names = '';
		foreach ( $this->sheets as $i => $sheet ) {
			$xml .= '<sheet name="' . self::esc( $sheet['name'] ) . '" sheetId="' . ( $i + 1 ) . '" r:id="rId' . ( $i + 1 ) . '"/>';
			if ( $sheet['filter'] && $sheet['count'] > 1 ) {
				$last   = '$' . self::column_letter( max( 1, count( $sheet['columns'] ) ) - 1 ) . '$' . $sheet['count'];
				$names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::esc( "'" . str_replace( "'", "''", $sheet['name'] ) . "'!\$A\$1:" . $last ) . '</definedName>';
			}
		}
		$xml .= '</sheets>';
		if ( '' !== $names ) {
			$xml .= '<definedNames>' . $names . '</definedNames>';
		}
		return $xml . '</workbook>';
	}

	/**
	 * Styles part. Order of cellXfs must match the S_* constants.
	 *
	 * @return string
	 */
	private function styles() {
		$money_fmt = 0 === $this->decimals ? 3 : 164;
		$font      = '<sz val="11"/><name val="Tahoma"/><family val="2"/>';
		$xml       = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml      .= '<styleSheet xmlns="' . self::NS_MAIN . '">';
		$xml      .= '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.' . str_repeat( '0', max( 1, $this->decimals ) ) . '"/><numFmt numFmtId="165" formatCode="0.0%"/></numFmts>';
		$xml      .= '<fonts count="4"><font>' . $font . '</font><font><b/><color rgb="FFFFFFFF"/>' . $font . '</font><font><u/><color rgb="FF0563C1"/>' . $font . '</font><font><b/>' . $font . '</font></fonts>';
		$xml      .= '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill></fills>';
		$xml      .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
		$xml      .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
		$xml      .= '<cellXfs count="7">';
		$xml      .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
		$xml      .= '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
		$xml      .= '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
		$xml      .= '<xf numFmtId="' . $money_fmt . '" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
		$xml      .= '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
		$xml      .= '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>';
		$xml      .= '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>';
		$xml      .= '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
		return $xml . '</styleSheet>';
	}

	/**
	 * [Content_Types].xml.
	 *
	 * @param int $n Sheets.
	 * @return string
	 */
	private function content_types( $n ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
		$xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
		$xml .= '<Default Extension="xml" ContentType="application/xml"/>';
		$xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
		for ( $i = 1; $i <= $n; $i++ ) {
			$xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
		$xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
		$xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
		return $xml . '</Types>';
	}

	/**
	 * A relationships part.
	 *
	 * @param array $list Each: [type, target].
	 * @return string
	 */
	private function rels( array $list ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="' . self::NS_PKG_REL . '">';
		foreach ( $list as $i => $r ) {
			$xml .= '<Relationship Id="rId' . ( $i + 1 ) . '" Type="' . $r[0] . '" Target="' . $r[1] . '"/>';
		}
		return $xml . '</Relationships>';
	}

	/**
	 * Core document properties.
	 *
	 * @param int $mtime Timestamp.
	 * @return string
	 */
	private function core( $mtime ) {
		$when = gmdate( 'Y-m-d\TH:i:s\Z', $mtime );
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$xml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
		$xml .= '<dc:title>' . self::esc( $this->title ) . '</dc:title><dc:creator>' . self::esc( $this->creator ) . '</dc:creator>';
		$xml .= '<dcterms:created xsi:type="dcterms:W3CDTF">' . $when . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $when . '</dcterms:modified>';
		return $xml . '</cp:coreProperties>';
	}

	/**
	 * Extended properties.
	 *
	 * @return string
	 */
	private function app() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Smart Bulk Discount</Application></Properties>';
	}

	/**
	 * Excel sheet names: ≤ 31 characters, none of []:*?/\, not empty, unique.
	 *
	 * @param string $name Wanted name.
	 * @param int    $n    Fallback number.
	 * @return string
	 */
	private function sheet_name( $name, $n ) {
		$name = (string) preg_replace( '/[\[\]:*?\/\\\\\x00-\x1F]/u', ' ', (string) $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ), " '" );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, 31, 'UTF-8' );
		}
		if ( '' === $name ) {
			$name = 'Sheet' . $n;
		}
		foreach ( $this->sheets as $s ) {
			if ( $s['name'] === $name ) {
				return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 27, 'UTF-8' ) . ' ' . $n : $name . ' ' . $n;
			}
		}
		return $name;
	}
}
