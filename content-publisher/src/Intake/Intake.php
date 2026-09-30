<?php
/**
 * Taking in uploaded posts: one job per post file, its images stored with it,
 * and the job queued for processing.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Intake;

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\ImageMatcher;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Jobs\Storage;
use CPub\Publisher\Pipeline\Pipeline;
use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Intake {

	public const FORMATS = array( 'txt', 'md', 'docx' );

	/**
	 * @param array<int, array{name:string, bytes:string}> $posts
	 * @param array<int, array{name:string, bytes:string}> $images
	 * @return array{batch:string, created:int[], skipped: array<int, array{name:string, reason:string, job?:int}>, images_unused:string[]}
	 * @throws IntakeException when nothing can be taken in at all
	 */
	public static function submit( int $site_id, array $posts, array $images, int $user_id, bool $allow_duplicates = false ): array {
		$site = Sites::get( $site_id );
		if ( ! $site || Sites::CONNECTED !== $site->status ) {
			throw new IntakeException( 'Choose a connected client site.' );
		}
		if ( ! $posts ) {
			throw new IntakeException( 'Choose at least one post file (.txt, .md or .docx).' );
		}

		$batch   = bin2hex( random_bytes( 16 ) );
		$out     = array( 'batch' => $batch, 'created' => array(), 'skipped' => array(), 'images_unused' => array() );
		$checked = array();
		foreach ( $images as $img ) {
			$type = Assets::sniff_image( $img['bytes'] );
			if ( strlen( $img['bytes'] ) > Assets::MAX_IMAGE_BYTES ) {
				$out['skipped'][] = array( 'name' => $img['name'], 'reason' => 'The image is larger than 10 MB.' );
			} elseif ( ! $type ) {
				$out['skipped'][] = array( 'name' => $img['name'], 'reason' => 'Not a JPEG, PNG, GIF or WebP image.' );
			} else {
				$checked[] = $img + $type;
			}
		}

		$accepted = array();
		foreach ( $posts as $post ) {
			$name = self::clean_name( $post['name'] );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::FORMATS, true ) ) {
				$out['skipped'][] = array( 'name' => $name, 'reason' => 'Only .txt, .md and .docx files can be posts.' );
				continue;
			}
			$limit = 'docx' === $ext ? Docx::MAX_FILE_BYTES : Pipeline::MAX_BYTES;
			if ( strlen( $post['bytes'] ) > $limit ) {
				$out['skipped'][] = array( 'name' => $name, 'reason' => 'docx' === $ext ? 'The Word file is larger than 25 MB.' : 'The file is larger than 200 KB; a blog post is much smaller.' );
				continue;
			}
			if ( '' === trim( $post['bytes'] ) ) {
				$out['skipped'][] = array( 'name' => $name, 'reason' => 'The file is empty.' );
				continue;
			}
			$sha = hash( 'sha256', $post['bytes'] );
			if ( in_array( $sha, array_column( $accepted, 'sha' ), true ) ) {
				$out['skipped'][] = array( 'name' => $name, 'reason' => 'The same file was chosen twice.' );
				continue;
			}
			$dup = Jobs::find_duplicate( $site_id, $sha );
			if ( $dup && ! $allow_duplicates ) {
				$out['skipped'][] = array(
					'name'   => $name,
					'reason' => sprintf( 'Already uploaded for this site on %s (%s).', get_date_from_gmt( (string) $dup->created_at, 'j M Y' ), Jobs::LABELS[ $dup->status ] ?? $dup->status ),
					'job'    => (int) $dup->id,
				);
				continue;
			}
			$accepted[] = array( 'name' => $name, 'ext' => $ext, 'bytes' => $post['bytes'], 'sha' => $sha );
		}

		// Which images go with which post: with one post, all of them; with several,
		// the ones each post refers to by name (a Word file's own images come later).
		$refs = array();
		foreach ( $accepted as $i => $p ) {
			$refs[ $i ] = 'docx' === $p['ext'] ? array() : ImageMatcher::references( \CPub\Publisher\Pipeline\Ingest::decode( $p['bytes'] )['text'] );
		}
		$used = array();

		foreach ( $accepted as $i => $p ) {
			$id = 0;
			try {
				$id  = Jobs::create(
					array(
						'site_id'       => $site_id,
						'title'         => pathinfo( $p['name'], PATHINFO_FILENAME ),
						'source_name'   => $p['name'],
						'source_format' => $p['ext'],
						'source_sha256' => $p['sha'],
						'batch_id'      => $batch,
						'created_by'    => $user_id,
					)
				);
				$dir = Storage::new_job_dir( $id );
				Jobs::update( $id, array( 'data' => array( 'dir' => $dir ) ) );
				$job = Jobs::get( $id );
				Assets::add( $job, Assets::SOURCE, 'upload', $p['name'], $p['bytes'], self::source_mime( $p['ext'] ) );
				$mine = array();
				foreach ( $checked as $k => $img ) {
					$wanted = 1 === count( $accepted ) || array_filter( $refs[ $i ], fn( $r ) => null !== ImageMatcher::find( $r, array( (object) array( 'filename' => $img['name'] ) ) ) );
					if ( $wanted ) {
						Assets::add( $job, Assets::IMAGE, 'upload', $img['name'], $img['bytes'], $img['mime'] );
						$mine[] = $k;
					}
				}
			} catch ( \Throwable $e ) {
				// Nothing half-made is left behind: no job without its files, none stuck in the queue.
				if ( $id ) {
					Jobs::delete( $id );
				}
				error_log( 'Content Publisher upload of ' . $p['name'] . ': ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$out['skipped'][] = array( 'name' => $p['name'], 'reason' => 'Could not be stored on the server: ' . $e->getMessage() );
				continue;
			}
			foreach ( $mine as $k ) {
				$used[ $k ] = true;
			}
			Events::log( 'job_uploaded', sprintf( 'Uploaded %s.', $p['name'] ), $site_id, $id, array( 'batch' => $batch ) );
			Processor::queue( $id );
			$out['created'][] = $id;
		}
		foreach ( $checked as $k => $img ) {
			if ( empty( $used[ $k ] ) && $accepted ) {
				$out['images_unused'][] = $img['name'];
			}
		}
		return $out;
	}

	/** Keep the name people gave the file (images are matched by it), minus any path or control characters. */
	private static function clean_name( string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		return '' === trim( $name ) ? 'upload' : mb_substr( $name, 0, 200 );
	}

	private static function source_mime( string $ext ): string {
		return array(
			'txt'  => 'text/plain',
			'md'   => 'text/markdown',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		)[ $ext ];
	}

	/**
	 * Files from a multiple file input, as [name, bytes].
	 *
	 * @param array $files one entry of $_FILES (name/tmp_name/error… arrays)
	 * @return array{0: array<int, array{name:string, bytes:string}>, 1: string[]} [files, problems]
	 */
	public static function from_upload( array $files ): array {
		$out      = array();
		$problems = array();
		$names    = (array) ( $files['name'] ?? array() );
		foreach ( $names as $i => $name ) {
			$error = (int) ( ( (array) ( $files['error'] ?? array() ) )[ $i ] ?? UPLOAD_ERR_NO_FILE );
			$tmp   = (string) ( ( (array) ( $files['tmp_name'] ?? array() ) )[ $i ] ?? '' );
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp ) ) {
				$problems[] = sprintf( '%s: %s', sanitize_file_name( (string) $name ), in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'larger than this server accepts (' . size_format( wp_max_upload_size() ) . ').' : 'the upload failed.' );
				continue;
			}
			$out[] = array( 'name' => (string) $name, 'bytes' => (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return array( $out, $problems );
	}
}
