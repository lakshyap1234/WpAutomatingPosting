<?php
/**
 * Read entries of a zip (a .docx is one) from memory, with size limits so a
 * small hostile file can't expand into gigabytes.
 *
 * Uses ZipArchive when PHP has it. Otherwise a small built-in reader (central
 * directory + zlib, inflating in small steps and stopping at the limit), not
 * WordPress's PclZip, which inflates a whole entry before any size check.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ZipReader {

	public const MAX_ENTRIES     = 2000;
	public const MAX_TOTAL_BYTES = 104857600; // 100 MB uncompressed in all

	/** Tests set this to exercise the built-in reader on a PHP that has ZipArchive. */
	public static bool $force_builtin = false;

	private const NOT_ZIP = 'This isn\'t a valid Word (.docx) file. If it\'s an old .doc file, save it as .docx and upload it again.';

	/** @var array<string, array{size:int, csize:int, method:int, offset:int}> */
	private array $entries = array();
	private int $read      = 0;
	private ?\ZipArchive $zip = null;
	private string $bytes  = '';
	private string $tmp    = '';

	/** @throws IntakeException when the bytes aren't a readable zip */
	public function __construct( string $bytes ) {
		if ( class_exists( \ZipArchive::class ) && ! self::$force_builtin ) {
			$this->open_zip_archive( $bytes );
		} else {
			$this->open_builtin( $bytes );
		}
		if ( count( $this->entries ) > self::MAX_ENTRIES ) {
			$this->close();
			throw new IntakeException( 'The Word file contains too many parts to be a normal document.' );
		}
	}

	private function open_zip_archive( string $bytes ): void {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; // not loaded in background jobs
		}
		$this->tmp = (string) wp_tempnam( 'cpub-docx' );
		if ( '' === $this->tmp || false === file_put_contents( $this->tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new IntakeException( 'Could not open the file on the server (temporary folder not writable).' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $this->tmp, \ZipArchive::RDONLY ) ) {
			$this->close();
			throw new IntakeException( self::NOT_ZIP );
		}
		$this->zip = $zip;
		for ( $i = 0; $i < $zip->numFiles && $i <= self::MAX_ENTRIES; $i++ ) {
			$st = $zip->statIndex( $i );
			if ( $st ) {
				$this->entries[ (string) $st['name'] ] = array( 'size' => (int) $st['size'], 'csize' => 0, 'method' => 0, 'offset' => 0 );
			}
		}
	}

	/** Parse the central directory ourselves. */
	private function open_builtin( string $bytes ): void {
		if ( ! function_exists( 'inflate_init' ) ) {
			throw new IntakeException( 'This server can\'t read Word files (PHP has neither the zip nor the zlib extension). Save the post as .txt instead.' );
		}
		$this->bytes = $bytes;
		$len         = strlen( $bytes );
		$eocd        = strrpos( substr( $bytes, max( 0, $len - 65557 ) ), "PK\x05\x06" );
		if ( false === $eocd ) {
			throw new IntakeException( self::NOT_ZIP );
		}
		$eocd += max( 0, $len - 65557 );
		$e     = unpack( 'vdisk/vcdisk/vcount_here/vcount/Vcd_size/Vcd_offset', substr( $bytes, $eocd + 4, 16 ) );
		if ( ! $e || $e['cd_offset'] + $e['cd_size'] > $len ) {
			throw new IntakeException( self::NOT_ZIP );
		}
		$pos = $e['cd_offset'];
		for ( $i = 0; $i < min( $e['count'], self::MAX_ENTRIES + 1 ); $i++ ) {
			if ( $pos + 46 > $len || "PK\x01\x02" !== substr( $bytes, $pos, 4 ) ) {
				throw new IntakeException( self::NOT_ZIP );
			}
			$h = unpack( 'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vname_len/vextra_len/vcomment_len/vdisk/vint_attr/Vext_attr/Voffset', substr( $bytes, $pos + 4, 42 ) );
			$name                    = substr( $bytes, $pos + 46, $h['name_len'] );
			$this->entries[ $name ] = array( 'size' => $h['size'], 'csize' => $h['csize'], 'method' => $h['method'], 'offset' => $h['offset'] );
			$pos                    += 46 + $h['name_len'] + $h['extra_len'] + $h['comment_len'];
		}
	}

	public function __destruct() {
		$this->close();
	}

	private function close(): void {
		if ( $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}
		if ( '' !== $this->tmp && is_file( $this->tmp ) ) {
			wp_delete_file( $this->tmp );
		}
		$this->tmp = '';
	}

	public function has( string $name ): bool {
		return isset( $this->entries[ $name ] );
	}

	/**
	 * An entry's bytes, or null if it isn't there.
	 *
	 * @throws IntakeException when it (or the total read so far) is too large
	 */
	public function get( string $name, int $max_bytes ): ?string {
		if ( ! $this->has( $name ) ) {
			return null;
		}
		// The declared size can lie; never read more than the limit plus one byte.
		if ( $this->entries[ $name ]['size'] > $max_bytes ) {
			throw new IntakeException( "Part of the Word file ({$name}) is too large." );
		}
		$data = $this->zip ? $this->zip->getFromName( $name, $max_bytes + 1 ) : $this->builtin_get( $name, $max_bytes );
		if ( false === $data ) {
			throw new IntakeException( "Part of the Word file ({$name}) is damaged." );
		}
		if ( strlen( $data ) > $max_bytes ) {
			throw new IntakeException( "Part of the Word file ({$name}) is too large." );
		}
		$this->read += strlen( $data );
		if ( $this->read > self::MAX_TOTAL_BYTES ) {
			throw new IntakeException( 'The Word file expands to too much data to be a normal document.' );
		}
		return $data;
	}

	/** @return string|false at most $max_bytes + 1 bytes */
	private function builtin_get( string $name, int $max_bytes ) {
		$e   = $this->entries[ $name ];
		$pos = $e['offset'];
		if ( "PK\x03\x04" !== substr( $this->bytes, $pos, 4 ) ) {
			return false;
		}
		$h    = unpack( 'vname_len/vextra_len', substr( $this->bytes, $pos + 26, 4 ) );
		$data = substr( $this->bytes, $pos + 30 + $h['name_len'] + $h['extra_len'], $e['csize'] );
		if ( 0 === $e['method'] ) {
			return substr( $data, 0, $max_bytes + 1 );
		}
		if ( 8 !== $e['method'] ) {
			return false;
		}
		$ctx = inflate_init( ZLIB_ENCODING_RAW );
		$out = '';
		// Small steps: 4 KB of input inflates to at most ~4 MB, so the check below bounds memory.
		for ( $i = 0, $n = strlen( $data ); $i < $n; $i += 4096 ) {
			$part = @inflate_add( $ctx, substr( $data, $i, 4096 ), ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $part ) {
				return false;
			}
			$out .= $part;
			if ( strlen( $out ) > $max_bytes ) {
				return substr( $out, 0, $max_bytes + 1 );
			}
		}
		return $out;
	}
}
