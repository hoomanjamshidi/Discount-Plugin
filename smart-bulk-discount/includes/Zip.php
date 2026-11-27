<?php
/**
 * Minimal ZIP writer (no ZipArchive needed — many shared hosts lack ext-zip).
 *
 * @package SmartBulkDiscount
 */

namespace SmartBulkDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a standard ZIP (deflate when zlib is available, otherwise "stored") to a stream.
 * Entries are read in 64 KB pieces, so a large worksheet never has to sit in memory twice.
 * Pure PHP 7.4, no WordPress: unit tested.
 */
final class Zip {

	const CHUNK = 65536;

	/** @var resource */
	private $out;

	/** @var array[] Central directory records. */
	private $entries = array();

	/** @var int Bytes written so far. */
	private $offset = 0;

	/** @var int DOS time. */
	private $dos_time;

	/** @var int DOS date. */
	private $dos_date;

	/** @var bool */
	private $deflate;

	/**
	 * Constructor.
	 *
	 * @param resource $out     Writable stream.
	 * @param int|null $mtime   Modification time (unix, UTC) for every entry.
	 * @param bool     $deflate Compress (false = "stored", used by tests to cover the fallback).
	 */
	public function __construct( $out, $mtime = null, $deflate = true ) {
		$this->out     = $out;
		$this->deflate = $deflate && function_exists( 'deflate_init' );
		$t             = getdate( null === $mtime ? time() : (int) $mtime );
		$year          = max( 1980, (int) $t['year'] );
		$this->dos_time = ( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | ( $t['seconds'] >> 1 );
		$this->dos_date = ( ( $year - 1980 ) << 9 ) | ( $t['mon'] << 5 ) | $t['mday'];
	}

	/**
	 * Add an entry from a string.
	 *
	 * @param string $name Path inside the archive (ASCII).
	 * @param string $data Contents.
	 */
	public function add_string( $name, $data ) {
		$src = fopen( 'php://temp', 'w+b' );
		fwrite( $src, (string) $data );
		$this->add_stream( $name, $src );
		fclose( $src );
	}

	/**
	 * Add an entry from a readable stream (read from the start).
	 *
	 * @param string   $name Path inside the archive (ASCII).
	 * @param resource $src  Stream.
	 */
	public function add_stream( $name, $src ) {
		rewind( $src );
		$crc  = hash_init( 'crc32b' );
		$size = 0;
		$tmp  = fopen( 'php://temp/maxmemory:4194304', 'w+b' );
		$ctx  = $this->deflate ? deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) ) : null;

		while ( ! feof( $src ) ) {
			$buf = fread( $src, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$size += strlen( $buf );
			hash_update( $crc, $buf );
			fwrite( $tmp, $ctx ? deflate_add( $ctx, $buf, ZLIB_NO_FLUSH ) : $buf );
		}
		if ( $ctx ) {
			fwrite( $tmp, deflate_add( $ctx, '', ZLIB_FINISH ) );
		}
		$csize = (int) ftell( $tmp );
		$sum   = unpack( 'N', hash_final( $crc, true ) );

		$entry = array(
			'name'   => (string) $name,
			'method' => $ctx ? 8 : 0,
			'crc'    => $sum[1],
			'csize'  => $csize,
			'size'   => $size,
			'offset' => $this->offset,
		);

		$this->write(
			pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, $entry['method'], $this->dos_time, $this->dos_date, $entry['crc'], $csize, $size, strlen( $entry['name'] ), 0 ) . $entry['name']
		);
		rewind( $tmp );
		while ( ! feof( $tmp ) ) {
			$buf = fread( $tmp, self::CHUNK );
			if ( false === $buf || '' === $buf ) {
				break;
			}
			$this->write( $buf );
		}
		fclose( $tmp );
		$this->entries[] = $entry;
	}

	/**
	 * Write the central directory. The archive is complete after this.
	 */
	public function finish() {
		$start = $this->offset;
		foreach ( $this->entries as $e ) {
			$this->write(
				pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $e['method'], $this->dos_time, $this->dos_date, $e['crc'], $e['csize'], $e['size'], strlen( $e['name'] ), 0, 0, 0, 0, 0, $e['offset'] ) . $e['name']
			);
		}
		$count = count( $this->entries );
		$this->write( pack( 'VvvvvVVv', 0x06054b50, 0, 0, $count, $count, $this->offset - $start, $start, 0 ) );
	}

	/**
	 * Write and count.
	 *
	 * @param string $bytes Bytes.
	 */
	private function write( $bytes ) {
		fwrite( $this->out, $bytes );
		$this->offset += strlen( $bytes );
	}
}
