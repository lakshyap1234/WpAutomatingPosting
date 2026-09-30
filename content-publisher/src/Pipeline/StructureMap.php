<?php
/**
 * The structure map is the AI's ONLY output. It references line numbers and
 * never contains post text, so the AI cannot change what gets published.
 *
 * Port of the prototype's src/schema.js (zod validation written out by hand).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StructureMap {

	public const EXCLUDED_KINDS = array( 'byline', 'date', 'metadata' );

	/**
	 * JSON Schema sent to the model. Each block type is its own anyOf variant
	 * with its fields required; list items are called list_items (a property
	 * named "items" collides with the JSON Schema keyword in Gemini).
	 */
	public static function schema(): array {
		$ids = fn( string $d ) => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => $d );
		return array(
			'type'       => 'object',
			'required'   => array( 'title', 'excluded', 'blocks' ),
			'properties' => array(
				'title'             => array( 'type' => 'integer', 'description' => 'ID of the line that is the post title.' ),
				'excluded'          => array(
					'type'        => 'array',
					'description' => 'Lines that are not body content: bylines, dates, and metadata lines such as "Tags: …" or "Category: …". Usually few. Never put body content here.',
					'items'       => array(
						'type'       => 'object',
						'required'   => array( 'line', 'kind' ),
						'properties' => array(
							'line' => array( 'type' => 'integer' ),
							'kind' => array( 'type' => 'string', 'enum' => self::EXCLUDED_KINDS ),
						),
					),
				),
				'blocks'            => array(
					'type'        => 'array',
					'description' => 'Body blocks in reading order.',
					'items'       => array(
						'anyOf' => array(
							array(
								'type'       => 'object',
								'title'      => 'Paragraph',
								'required'   => array( 'type', 'lines' ),
								'properties' => array(
									'type'   => array( 'type' => 'string', 'enum' => array( 'paragraph' ) ),
									'lines'  => $ids( 'Consecutive line IDs joined into one paragraph.' ),
									'breaks' => array( 'type' => 'boolean', 'description' => 'Keep line breaks (addresses, verse).' ),
								),
							),
							array(
								'type'       => 'object',
								'title'      => 'Heading',
								'required'   => array( 'type', 'level', 'lines' ),
								'properties' => array(
									'type'  => array( 'type' => 'string', 'enum' => array( 'heading' ) ),
									'level' => array( 'type' => 'integer', 'enum' => array( 2, 3, 4 ) ),
									'lines' => $ids( 'Exactly one line ID.' ),
								),
							),
							array(
								'type'       => 'object',
								'title'      => 'List',
								'required'   => array( 'type', 'ordered', 'list_items' ),
								'properties' => array(
									'type'       => array( 'type' => 'string', 'enum' => array( 'list' ) ),
									'ordered'    => array( 'type' => 'boolean' ),
									'list_items' => array(
										'type'        => 'array',
										'description' => 'One entry per list item; each entry is the consecutive line IDs of that item.',
										'items'       => $ids( 'Line IDs of one list item.' ),
									),
								),
							),
							array(
								'type'       => 'object',
								'title'      => 'Table',
								'required'   => array( 'type', 'header', 'lines' ),
								'properties' => array(
									'type'   => array( 'type' => 'string', 'enum' => array( 'table' ) ),
									'header' => array( 'type' => 'boolean', 'description' => 'True if the first row holds column titles.' ),
									'lines'  => $ids( 'Consecutive line IDs of every row, including any |---| separator row.' ),
								),
							),
							array(
								'type'       => 'object',
								'title'      => 'Image',
								'required'   => array( 'type', 'lines' ),
								'properties' => array(
									'type'  => array( 'type' => 'string', 'enum' => array( 'image' ) ),
									'lines' => $ids( 'Consecutive line IDs of one image: its [IMAGE: …], Image URL:, Alt text: and Caption: lines.' ),
								),
							),
						),
					),
				),
				'notes_not_applied' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Revision requests you could not apply (e.g. requests to change wording).',
				),
			),
		);
	}

	/**
	 * Model format -> internal format. Also salvages a list given as flat
	 * `lines`: one item per line, with a warning for the reviewer.
	 *
	 * @return array{0: mixed, 1: string[]} [input, warnings]
	 */
	public static function from_model( $input ): array {
		$warnings = array();
		if ( ! is_array( $input ) || ! isset( $input['blocks'] ) || ! is_array( $input['blocks'] ) || ! array_is_list( $input['blocks'] ) ) {
			return array( $input, $warnings );
		}
		foreach ( $input['blocks'] as $i => $b ) {
			if ( ! is_array( $b ) || 'list' !== ( $b['type'] ?? null ) || isset( $b['items'] ) ) {
				continue;
			}
			if ( isset( $b['list_items'] ) && is_array( $b['list_items'] ) ) {
				$b['items'] = $b['list_items'];
				unset( $b['list_items'], $b['lines'] );
			} elseif ( isset( $b['lines'] ) && is_array( $b['lines'] ) ) {
				$warnings[] = 'List at block ' . ( $i + 1 ) . ' came back without item grouping; assumed one item per line. Check wrapped items in the preview.';
				$b['items'] = array_map( fn( $id ) => array( $id ), $b['lines'] );
				unset( $b['lines'], $b['list_items'] );
			}
			$input['blocks'][ $i ] = $b;
		}
		return array( $input, $warnings );
	}

	/** Internal format -> model format (sending the current map back for revision). */
	public static function to_model( array $map ): array {
		unset( $map['notes_not_applied'] );
		foreach ( $map['blocks'] as $i => $b ) {
			if ( 'list' === $b['type'] ) {
				$b['list_items'] = $b['items'];
				unset( $b['items'] );
				$map['blocks'][ $i ] = $b;
			}
		}
		return $map;
	}

	public static function block_line_ids( array $block ): array {
		return 'list' === $block['type'] ? array_merge( ...$block['items'] ) : $block['lines'];
	}

	// -----------------------------------------------------------------
	// Shape check (what zod did in the prototype)
	// -----------------------------------------------------------------

	private static function is_id( $v ): bool {
		// A bound keeps huge floats from wrapping around into a real line number.
		return ( is_int( $v ) || ( is_float( $v ) && floor( $v ) === $v ) ) && $v > 0 && $v <= 1000000;
	}

	private static function ids( $v, string $path, array &$errors, ?int $exact = null ): ?array {
		if ( ! is_array( $v ) || ! array_is_list( $v ) ) {
			$errors[] = "{$path}: Expected array";
			return null;
		}
		if ( null !== $exact && count( $v ) !== $exact ) {
			$errors[] = "{$path}: Array must contain exactly {$exact} element(s)";
			return null;
		}
		if ( ! $v ) {
			$errors[] = "{$path}: Array must contain at least 1 element(s)";
			return null;
		}
		foreach ( $v as $k => $id ) {
			if ( ! self::is_id( $id ) ) {
				$errors[] = "{$path}.{$k}: Expected a positive integer line ID";
				return null;
			}
		}
		return array_map( 'intval', $v );
	}

	/** @return array{0: ?array, 1: string[]} [clean map, errors] */
	private static function parse( $input ): array {
		$errors = array();
		if ( ! is_array( $input ) ) {
			return array( null, array( '(root): Expected object' ) );
		}
		$map = array( 'excluded' => array(), 'blocks' => array() );

		if ( ! self::is_id( $input['title'] ?? null ) ) {
			$errors[] = 'title: Expected a positive integer line ID';
		} else {
			$map['title'] = (int) $input['title'];
		}

		$excluded = $input['excluded'] ?? array();
		if ( ! is_array( $excluded ) || ! array_is_list( $excluded ) ) {
			$errors[] = 'excluded: Expected array';
		} else {
			foreach ( $excluded as $k => $e ) {
				if ( ! is_array( $e ) || ! self::is_id( $e['line'] ?? null ) ) {
					$errors[] = "excluded.{$k}.line: Expected a positive integer line ID";
				} elseif ( ! in_array( $e['kind'] ?? null, self::EXCLUDED_KINDS, true ) ) {
					$errors[] = "excluded.{$k}.kind: Invalid option: expected one of " . implode( '|', self::EXCLUDED_KINDS );
				} else {
					$map['excluded'][] = array( 'line' => (int) $e['line'], 'kind' => $e['kind'] );
				}
			}
		}

		$blocks = $input['blocks'] ?? null;
		if ( ! is_array( $blocks ) || ! array_is_list( $blocks ) ) {
			$errors[] = 'blocks: Expected array';
		} elseif ( ! $blocks ) {
			$errors[] = 'blocks: Array must contain at least 1 element(s)';
		} else {
			foreach ( $blocks as $i => $b ) {
				$p    = "blocks.{$i}";
				$type = is_array( $b ) && is_string( $b['type'] ?? null ) ? $b['type'] : null; // strict: "type": true is not a paragraph
				switch ( $type ) {
					case 'paragraph':
						$lines = self::ids( $b['lines'] ?? null, "{$p}.lines", $errors );
						if ( null !== $lines ) {
							$blk = array( 'type' => 'paragraph', 'lines' => $lines );
							if ( isset( $b['breaks'] ) ) {
								if ( ! is_bool( $b['breaks'] ) ) {
									$errors[] = "{$p}.breaks: Expected boolean";
									break;
								}
								$blk['breaks'] = $b['breaks'];
							}
							$map['blocks'][] = $blk;
						}
						break;
					case 'heading':
						if ( ! in_array( $b['level'] ?? null, array( 2, 3, 4 ), true ) ) {
							$errors[] = "{$p}.level: Invalid input: expected 2, 3 or 4";
							break;
						}
						$lines = self::ids( $b['lines'] ?? null, "{$p}.lines", $errors, 1 );
						if ( null !== $lines ) {
							$map['blocks'][] = array( 'type' => 'heading', 'level' => $b['level'], 'lines' => $lines );
						}
						break;
					case 'list':
						if ( ! is_bool( $b['ordered'] ?? null ) ) {
							$errors[] = "{$p}.ordered: Expected boolean";
							break;
						}
						$items = $b['items'] ?? null;
						if ( ! is_array( $items ) || ! array_is_list( $items ) || ! $items ) {
							$errors[] = "{$p}.items: Expected a non-empty array of line ID arrays";
							break;
						}
						$clean = array();
						foreach ( $items as $k => $item ) {
							$ids = self::ids( $item, "{$p}.items.{$k}", $errors );
							if ( null === $ids ) {
								break 2;
							}
							$clean[] = $ids;
						}
						$map['blocks'][] = array( 'type' => 'list', 'ordered' => $b['ordered'], 'items' => $clean );
						break;
					case 'table':
						$lines = self::ids( $b['lines'] ?? null, "{$p}.lines", $errors );
						if ( null !== $lines ) {
							$header = $b['header'] ?? true;
							if ( ! is_bool( $header ) ) {
								$errors[] = "{$p}.header: Expected boolean";
								break;
							}
							$map['blocks'][] = array( 'type' => 'table', 'lines' => $lines, 'header' => $header );
						}
						break;
					case 'image':
						$lines = self::ids( $b['lines'] ?? null, "{$p}.lines", $errors );
						if ( null !== $lines ) {
							$map['blocks'][] = array( 'type' => 'image', 'lines' => $lines );
						}
						break;
					default:
						$errors[] = "{$p}.type: Invalid input: expected one of paragraph|heading|list|table|image";
				}
			}
		}
		if ( isset( $input['notes_not_applied'] ) && is_array( $input['notes_not_applied'] ) ) {
			$map['notes_not_applied'] = array_values( array_filter( $input['notes_not_applied'], 'is_string' ) );
		}
		return array( $errors ? null : $map, $errors );
	}

	/**
	 * Parse and check a map against the source lines.
	 * Errors are fed back to the model on retry; warnings go to the reviewer.
	 *
	 * @return array{ok:bool, map?:array, errors:string[], warnings:string[]}
	 */
	public static function validate( $raw, array $lines ): array {
		[ $input, $conversion_warnings ] = self::from_model( $raw );
		[ $map, $shape_errors ]          = self::parse( $input );
		if ( $shape_errors ) {
			return array( 'ok' => false, 'errors' => $shape_errors, 'warnings' => array() );
		}
		$n        = count( $lines );
		$errors   = array();
		$warnings = $conversion_warnings;
		$seen     = array();
		$mark     = function ( int $id, string $where ) use ( &$seen, &$errors, $n ) {
			if ( $id < 1 || $id > $n ) {
				$errors[] = "{$where}: line {$id} does not exist (valid: 1-{$n})";
			} elseif ( isset( $seen[ $id ] ) ) {
				$errors[] = "line {$id} used twice ({$seen[$id]} and {$where})";
			} else {
				$seen[ $id ] = $where;
			}
		};

		$mark( $map['title'], 'title' );
		foreach ( $map['excluded'] as $e ) {
			$mark( $e['line'], 'excluded' );
		}
		$prev_last = 0;
		foreach ( $map['blocks'] as $i => $b ) {
			$where = "blocks[{$i}] ({$b['type']})";
			$ids   = self::block_line_ids( $b );
			foreach ( $ids as $id ) {
				$mark( $id, $where );
			}
			for ( $k = 1; $k < count( $ids ); $k++ ) {
				if ( $ids[ $k ] !== $ids[ $k - 1 ] + 1 ) {
					$errors[] = "{$where}: lines must be consecutive, got " . implode( ',', $ids );
				}
			}
			if ( $ids[0] <= $prev_last ) {
				$errors[] = "{$where}: blocks must be in reading order (starts at {$ids[0]} after {$prev_last})";
			}
			$prev_last = $ids[ count( $ids ) - 1 ];
		}

		$missing = array();
		for ( $id = 1; $id <= $n; $id++ ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$missing[] = $id;
			}
		}
		if ( $missing ) {
			$sample   = implode( '; ', array_map( fn( $id ) => "L{$id} \"" . Text::head( $lines[ $id - 1 ]['text'], 50 ) . '"', array_slice( $missing, 0, 8 ) ) );
			$errors[] = 'lines not assigned anywhere (every line must be used exactly once): ' . implode( ', ', $missing ) . ". Their text: {$sample}";
		}

		// Tables and images must parse from the original text; otherwise the model
		// marked the wrong lines and gets the reason on retry.
		$src      = fn( array $ids ) => array_map( fn( $id ) => $lines[ $id - 1 ], $ids );
		$in_range = fn( array $b ) => array_reduce( self::block_line_ids( $b ), fn( $ok, $id ) => $ok && $id >= 1 && $id <= $n, true );
		foreach ( $map['blocks'] as $i => $b ) {
			if ( ! $in_range( $b ) ) {
				continue;
			}
			if ( 'table' === $b['type'] ) {
				$t = Blocks::parse_table( $src( $b['lines'] ) );
				if ( ! $t['ok'] ) {
					$errors[] = $t['error'];
				} elseif ( $t['header'] ) {
					$map['blocks'][ $i ]['header'] = true; // a |---| row after the first row settles it
				}
			}
			if ( 'image' === $b['type'] ) {
				$img = Blocks::parse_image( $src( $b['lines'] ) );
				if ( ! $img['ok'] ) {
					$errors[] = $img['error'];
				}
			}
		}
		// Image lines left as paragraphs would publish "Image URL: …" as text.
		foreach ( $map['blocks'] as $b ) {
			if ( 'paragraph' === $b['type'] && $in_range( $b ) ) {
				$stray = array_values( array_filter( $b['lines'], fn( $id ) => Blocks::looks_like_image_line( $lines[ $id - 1 ]['text'] ) ) );
				if ( $stray ) {
					$errors[] = 'Line(s) ' . implode( ', ', $stray ) . ' describe an image; put them in an image block, not a paragraph.';
				}
			}
		}
		if ( $errors ) {
			return array( 'ok' => false, 'errors' => $errors, 'warnings' => $warnings );
		}

		// Soft checks, shown to the reviewer, not retried.
		$text  = fn( int $id ) => $lines[ $id - 1 ]['text'];
		$title = $text( $map['title'] );
		$tlen  = mb_strlen( $title );
		if ( $tlen > 150 ) {
			$warnings[] = "Title is {$tlen} chars — may not be a real title.";
		}
		if ( preg_match( '/[.!?]$/u', $title ) && count( explode( ' ', $title ) ) > 12 ) {
			$warnings[] = 'Title reads like a sentence — check the title pick.';
		}
		if ( $map['title'] > 3 ) {
			$warnings[] = "Title picked from line {$map['title']}, not the top of the file.";
		}
		foreach ( $map['excluded'] as $e ) {
			$len = mb_strlen( $text( $e['line'] ) );
			if ( $len > 80 ) {
				$warnings[] = "Excluded line {$e['line']} is long ({$len} chars) — might be body content.";
			}
			if ( $e['line'] > 4 && $e['line'] < $n - 2 ) {
				$warnings[] = "Excluded line {$e['line']} is in the middle of the post — might be body content.";
			}
		}
		foreach ( $map['blocks'] as $b ) {
			if ( 'heading' === $b['type'] && mb_strlen( $text( $b['lines'][0] ) ) > 100 ) {
				$warnings[] = "Heading on line {$b['lines'][0]} is " . mb_strlen( $text( $b['lines'][0] ) ) . ' chars.';
			}
			if ( 'table' === $b['type'] && Blocks::parse_table( $src( $b['lines'] ) )['ragged'] ) {
				$warnings[] = "The table on lines {$b['lines'][0]}–" . $b['lines'][ count( $b['lines'] ) - 1 ] . ' has rows with different numbers of cells; empty cells were added.';
			}
			if ( 'image' === $b['type'] ) {
				$img = Blocks::parse_image( $src( $b['lines'] ) );
				if ( '' === $img['url'] ) {
					$warnings[] = 'Image ' . ( '' !== $img['filename'] ? "\"{$img['filename']}\" " : '' ) . "on line {$b['lines'][0]} has no Image URL: it needs an image file with that name uploaded with the post, or a web address.";
				}
				if ( '' === $img['alt'] ) {
					$warnings[] = "Image on line {$b['lines'][0]} has no alt text. Add a description for screen readers and search engines.";
				}
			}
		}
		return array( 'ok' => true, 'map' => $map, 'errors' => array(), 'warnings' => $warnings );
	}

	/**
	 * Last resort after retries: lines the model left out become their own
	 * paragraphs (the text is still published as written), with a warning.
	 * An image line next to an image block (usually a forgotten [IMAGE] marker)
	 * joins that image instead.
	 */
	public static function repair_unassigned( $raw, array $lines ): ?array {
		if ( ! is_array( $raw ) || ! isset( $raw['blocks'] ) || ! is_array( $raw['blocks'] ) ) {
			return null;
		}
		$ids_of = function ( $b ): array {
			if ( ! is_array( $b ) ) {
				return array();
			}
			if ( isset( $b['lines'] ) && is_array( $b['lines'] ) ) {
				return $b['lines'];
			}
			$items = $b['list_items'] ?? $b['items'] ?? array();
			return is_array( $items ) ? array_merge( ...array_values( array_map( fn( $i ) => (array) $i, $items ?: array( array() ) ) ) ) : array(); // array_values: string keys would be named arguments
		};
		$used = array( $raw['title'] ?? null );
		foreach ( (array) ( $raw['excluded'] ?? array() ) as $e ) {
			$used[] = is_array( $e ) ? ( $e['line'] ?? null ) : null;
		}
		foreach ( $raw['blocks'] as $b ) {
			$used = array_merge( $used, $ids_of( $b ) );
		}
		$missing = array_values( array_filter( array_column( $lines, 'id' ), fn( $id ) => ! in_array( $id, $used, false ) ) );
		if ( ! $missing ) {
			return null;
		}

		$blocks = array_values( $raw['blocks'] );
		$notes  = array();
		foreach ( $missing as $id ) {
			if ( Blocks::looks_like_image_line( $lines[ $id - 1 ]['text'] ) ) {
				$joined = false;
				foreach ( $blocks as $k => $b ) { // the image that starts right after this line
					if ( is_array( $b ) && 'image' === ( $b['type'] ?? '' ) && is_array( $b['lines'] ?? null ) && ( $b['lines'][0] ?? null ) === $id + 1 ) {
						array_unshift( $blocks[ $k ]['lines'], $id );
						$joined = true;
						break;
					}
				}
				if ( ! $joined ) {
					foreach ( $blocks as $k => $b ) { // or ends right before it
						if ( is_array( $b ) && 'image' === ( $b['type'] ?? '' ) && is_array( $b['lines'] ?? null ) && end( $b['lines'] ) === $id - 1 ) {
							$blocks[ $k ]['lines'][] = $id;
							$joined                  = true;
							break;
						}
					}
				}
				if ( $joined ) {
					continue;
				}
			}
			$blocks[] = array( 'type' => 'paragraph', 'lines' => array( $id ) );
			$notes[]  = "Line {$id} (“" . Text::head( $lines[ $id - 1 ]['text'], 60 ) . '”) wasn’t placed by the AI, so it was kept as its own paragraph. Check it.';
		}
		usort( $blocks, fn( $a, $b ) => ( $ids_of( $a )[0] ?? 0 ) <=> ( $ids_of( $b )[0] ?? 0 ) );
		$raw['blocks'] = $blocks;
		$result        = self::validate( $raw, $lines );
		if ( ! $result['ok'] ) {
			return $result;
		}
		$result['warnings'] = array_merge( $notes, $result['warnings'] );
		return $result;
	}
}
