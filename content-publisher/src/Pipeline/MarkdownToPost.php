<?php
/**
 * Markdown -> WordPress post: title, Gutenberg block markup, problems found,
 * and the plain text (for the fidelity check and the list of text changes).
 *
 * Port of markdownToPost in the prototype's src/markdown.js. The prototype
 * used markdown-it; this uses league/commonmark for parsing and renders the
 * HTML itself, so the output matches the prototype's character for character
 * (checked by tools/parity).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

use CPub\Publisher\Vendor\League\CommonMark\Environment\Environment;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Delimiter\Processor\EmphasisDelimiterProcessor;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block as CoreBlock;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline as CoreInline;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Table\Table;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Table\TableCell;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Table\TableExtension;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Table\TableRow;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Table\TableSection;
use CPub\Publisher\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use CPub\Publisher\Vendor\League\CommonMark\Node\Block\Paragraph;
use CPub\Publisher\Vendor\League\CommonMark\Node\Inline\Newline;
use CPub\Publisher\Vendor\League\CommonMark\Node\Inline\Text as TextNode;
use CPub\Publisher\Vendor\League\CommonMark\Node\Node;
use CPub\Publisher\Vendor\League\CommonMark\Parser\MarkdownParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MarkdownToPost {

	private const UNSUPPORTED = array(
		BlockQuote::class    => 'Quotes (lines starting with >) aren’t supported yet.',
		IndentedCode::class  => 'Indented code blocks aren’t supported. Remove the indentation at the start of the line.',
		FencedCode::class    => 'Code blocks (```) aren’t supported yet.',
		ThematicBreak::class => 'Horizontal lines (---) aren’t supported yet. To show dashes as text, put a backslash first: \\---',
		HtmlBlock::class     => 'HTML isn’t allowed.',
	);

	private static ?MarkdownParser $parser = null;

	/**
	 * CommonMark + GFM tables, without raw HTML (it isn't even parsed: "<div>"
	 * stays text, as with markdown-it's html:false), plus bare-URL linking.
	 */
	private static function parser(): MarkdownParser {
		if ( null === self::$parser ) {
			$env = new Environment( array( 'table' => array( 'wrap' => array( 'enabled' => false ) ) ) );
			$env->addExtension( new SafeMarkdownExtension() );
			$env->addExtension( new TableExtension() );
			self::$parser = new MarkdownParser( $env );
		}
		return self::$parser;
	}

	/**
	 * Percent-encode a URL the way markdown-it does (mdurl.encode): keep letters,
	 * digits, ;/?:@&=+$,-_.!~*'()# and existing %XX escapes.
	 */
	public static function normalize_link( string $url ): string {
		return (string) preg_replace_callback(
			'/%[0-9A-Fa-f]{2}|[^A-Za-z0-9;\/?:@&=+$,\-_.!~*\'()#]/u',
			fn( $m ) => '%' === $m[0][0] && 3 === strlen( $m[0] ) ? $m[0] : strtoupper( implode( '', array_map( fn( $b ) => '%' . bin2hex( $b ), str_split( $m[0] ) ) ) ),
			$url
		);
	}

	/**
	 * @param array{format?:string, media?:array, local?:array} $opts format: blocks|html.
	 *   media: src => [id, src, sizeSlug] for images uploaded to the client site.
	 *   local: src => preview address, for image files uploaded with the post (not sent yet).
	 * @return array{title:string, content:string, errors:array, plain_text:string, counts:array, images:array}
	 */
	public static function convert( string $markdown, array $opts = array() ): array {
		$format = $opts['format'] ?? 'blocks';
		$media  = $opts['media'] ?? array();
		$local  = $opts['local'] ?? array();
		$doc    = self::parser()->parse( $markdown );
		$errors = array();
		$out    = array();
		$text   = array();
		$images = array();
		$counts = array( 'headings' => 0, 'paragraphs' => 0, 'lists' => 0, 'tables' => 0, 'images' => 0 );
		$title  = null;

		$line       = fn( Node $n ) => $n instanceof AbstractBlock ? $n->getStartLine() : null;
		$add_error  = function ( ?int $l, string $message ) use ( &$errors ) {
			$errors[] = array( 'line' => $l, 'message' => $message );
		};
		$check      = function ( Node $container ) use ( $add_error, $line ) {
			foreach ( self::descendants( $container ) as $n ) {
				if ( $n instanceof Image ) {
					$add_error( $line( $container ), 'An image can’t be inside a heading, list or table. Put it on its own line, with an empty line before and after.' );
					return;
				}
			}
		};
		$require_title = function ( Node $n ) use ( &$title, &$errors, $add_error, $line ) {
			if ( null === $title && ! array_filter( $errors, fn( $e ) => str_contains( $e['message'], 'start with the title' ) ) ) {
				$add_error( $line( $n ), 'The post must start with the title line: # Your title' );
			}
		};

		foreach ( self::children( $doc ) as $node ) {
			foreach ( self::UNSUPPORTED as $class => $message ) {
				if ( $node instanceof $class ) {
					$add_error( $line( $node ), $message );
					continue 2;
				}
			}

			if ( $node instanceof Heading ) {
				$level = $node->getLevel();
				$check( $node );
				if ( 1 === $level ) {
					if ( null !== $title || $out ) {
						$add_error( $line( $node ), 'Only the first line can be the title (#). Use ## for a heading.' );
					} else {
						$title = self::plain( $node );
					}
				} elseif ( $level > 4 ) {
					$add_error( $line( $node ), str_repeat( '#', $level ) . ' is too deep. Use ##, ### or ####.' );
				} else {
					if ( null === $title ) {
						$add_error( $line( $node ), 'The post must start with the title line: # Your title' );
					}
					$cls   = 'blocks' === $format ? ' class="wp-block-heading"' : '';
					$out[] = self::wrap( $format, 'heading', 2 === $level ? null : array( 'level' => $level ), "<h{$level}{$cls}>" . self::inline_html( $node ) . "</h{$level}>" );
					$text[] = self::plain( $node );
					++$counts['headings'];
				}
				continue;
			}

			if ( $node instanceof Paragraph ) {
				$require_title( $node );
				$kids = self::children( $node );
				$imgs = array_values( array_filter( $kids, fn( $k ) => $k instanceof Image ) );
				if ( $imgs ) {
					$only = 1 === count( $imgs ) && array_reduce( $kids, fn( $ok, $k ) => $ok && ( $k instanceof Image || ( $k instanceof TextNode && '' === Text::trim( $k->getLiteral() ) ) || ( $k instanceof Newline && Newline::SOFTBREAK === $k->getType() ) ), true );
					if ( ! $only ) {
						$add_error( $line( $node ), 'Put each image on its own line, with an empty line before and after it.' );
					} else {
						$img     = $imgs[0];
						$src     = $img->getUrl();
						$alt     = Text::trim( self::alt_text( $img ) );
						$caption = Text::trim( (string) $img->getTitle() );
						$file = isset( $media[ $src ] ) || isset( $local[ $src ] );
						if ( ! $file && ! self::is_web_address( $src ) ) {
							$add_error(
								$line( $node ),
								preg_match( '/^https?:\/\/?$/i', $src )
									? 'This image still has the placeholder address. Replace https:// with the image’s full web address.'
									: 'Image “' . ( '' !== $src ? rawurldecode( $src ) : '(empty)' ) . '” needs a web address starting with https://, or an image file with that name uploaded with the post.'
							);
						}
						$images[] = array( 'src' => $src, 'alt' => $alt, 'caption' => $caption, 'line' => $line( $node ), 'file' => $file );
						// No markup at all for an address that isn't a web address (javascript:, a bare file name…).
						if ( isset( $media[ $src ] ) || self::is_web_address( $src ) ) {
							$out[] = self::image_block( $src, $alt, $caption, $media[ $src ] ?? null, $format );
						} elseif ( isset( $local[ $src ] ) ) {
							$out[] = self::image_block( (string) $local[ $src ], $alt, $caption, null, $format );
						}
						$text[]   = Blocks::image_text( array( 'url' => $src, 'alt' => $alt, 'caption' => $caption ) );
						++$counts['images'];
					}
					continue;
				}
				$out[]  = self::wrap( $format, 'paragraph', null, '<p>' . self::inline_html( $node ) . '</p>' );
				$text[] = self::plain( $node );
				++$counts['paragraphs'];
				continue;
			}

			if ( $node instanceof Table ) {
				$require_title( $node );
				$head = array();
				$body = array();
				foreach ( self::children( $node ) as $section ) {
					if ( ! $section instanceof TableSection ) {
						continue;
					}
					foreach ( self::children( $section ) as $row ) {
						if ( ! $row instanceof TableRow ) {
							continue;
						}
						$cells = array_values( array_filter( self::children( $row ), fn( $c ) => $c instanceof TableCell ) );
						foreach ( $cells as $c ) {
							$check( $c );
						}
						if ( $section->isHead() ) {
							$head[] = $cells;
						} else {
							$body[] = $cells;
						}
					}
				}
				$headless = array_reduce( $head, fn( $ok, $r ) => $ok && array_reduce( $r, fn( $ok2, $c ) => $ok2 && '' === self::plain( $c ), true ), true );
				$tr       = fn( array $cells, string $tag ) => '<tr>' . implode( '', array_map( fn( $c ) => "<{$tag}>" . self::inline_html( $c ) . "</{$tag}>", $cells ) ) . '</tr>';
				$thead    = $headless ? '' : '<thead>' . implode( '', array_map( fn( $r ) => $tr( $r, 'th' ), $head ) ) . '</thead>';
				$tbody    = $body ? '<tbody>' . implode( '', array_map( fn( $r ) => $tr( $r, 'td' ), $body ) ) . '</tbody>' : '';
				// Matches the core Table block's saved markup (hasFixedLayout defaults to true).
				$out[]  = self::wrap( $format, 'table', null, '<figure class="wp-block-table"><table class="has-fixed-layout">' . $thead . $tbody . '</table></figure>' );
				$cells  = array_merge( ...array_merge( $headless ? array() : $head, $body ) ?: array( array() ) );
				$text[] = implode( ' ', array_filter( array_map( array( self::class, 'plain' ), $cells ), 'strlen' ) );
				++$counts['tables'];
				continue;
			}

			if ( $node instanceof ListBlock ) {
				$data    = $node->getListData();
				$ordered = ListBlock::TYPE_ORDERED === $data->type;
				$start   = $ordered ? (int) ( $data->start ?? 1 ) : 1;
				$lis     = array();
				foreach ( self::children( $node ) as $item ) {
					if ( ! $item instanceof ListItem ) {
						continue;
					}
					$inlines = array();
					self::collect_item( $item, $inlines, $add_error, $check, $line );
					$html   = implode( '<br>', array_map( array( self::class, 'inline_html' ), $inlines ) );
					$text[] = implode( ' ', array_map( array( self::class, 'plain' ), $inlines ) );
					$lis[]  = 'blocks' === $format ? "<!-- wp:list-item -->\n<li>{$html}</li>\n<!-- /wp:list-item -->" : "<li>{$html}</li>";
				}
				$tag   = $ordered ? 'ol' : 'ul';
				$cls   = 'blocks' === $format ? ' class="wp-block-list"' : '';
				$sa    = $ordered && 1 !== $start ? " start=\"{$start}\"" : '';
				$attrs = $ordered ? ( 1 !== $start ? array( 'ordered' => true, 'start' => $start ) : array( 'ordered' => true ) ) : null;
				$out[] = self::wrap( $format, 'list', $attrs, "<{$tag}{$sa}{$cls}>" . implode( 'blocks' === $format ? "\n\n" : "\n", $lis ) . "</{$tag}>" );
				++$counts['lists'];
				continue;
			}
		}

		if ( null === $title && ! $errors ) {
			$add_error( 1, 'The post must start with the title line: # Your title' );
		}
		if ( null !== $title && '' === $title ) {
			$add_error( 1, 'The title is empty.' );
		}
		if ( ! $out && ! $errors ) {
			$add_error( null, 'The post has no body text.' );
		}

		return array(
			'title'      => $title ?? '',
			'content'    => implode( "\n\n", $out ),
			'errors'     => $errors,
			'plain_text' => Text::squash( implode( ' ', $text ) ),
			'counts'     => $counts,
			'images'     => $images,
		);
	}

	/**
	 * The inline containers of a list item, in order (markdown-it collects every
	 * inline token between the item's open and close, except nested lists).
	 */
	private static function collect_item( Node $container, array &$inlines, callable $add_error, callable $check, callable $line ): void {
		foreach ( self::children( $container ) as $child ) {
			if ( $child instanceof ListBlock ) {
				$add_error( $line( $child ), 'Lists inside lists aren’t supported yet.' );
				continue;
			}
			foreach ( self::UNSUPPORTED as $class => $message ) {
				if ( $child instanceof $class ) {
					$add_error( $line( $child ), $message );
					if ( $child instanceof BlockQuote ) {
						self::collect_item( $child, $inlines, $add_error, $check, $line );
					}
					continue 2;
				}
			}
			if ( $child instanceof Paragraph || $child instanceof Heading ) {
				$check( $child );
				$inlines[] = $child;
			} elseif ( $child instanceof Table ) {
				foreach ( self::descendants( $child ) as $cell ) {
					if ( $cell instanceof TableCell ) {
						$check( $cell );
						$inlines[] = $cell;
					}
				}
			} else {
				self::collect_item( $child, $inlines, $add_error, $check, $line );
			}
		}
	}

	/** @return Node[] */
	private static function children( Node $n ): array {
		$out = array();
		for ( $c = $n->firstChild(); null !== $c; $c = $c->next() ) {
			$out[] = $c;
		}
		return $out;
	}

	/** @return Node[] depth-first */
	private static function descendants( Node $n ): array {
		$out = array();
		foreach ( self::children( $n ) as $c ) {
			$out[] = $c;
			array_push( $out, ...self::descendants( $c ) );
		}
		return $out;
	}

	/** Visible text: text and code, line breaks as spaces, image alt text left out. */
	public static function plain( Node $n ): string {
		return Text::squash( self::plain_raw( $n ) );
	}

	private static function plain_raw( Node $n ): string {
		$s = '';
		foreach ( self::children( $n ) as $c ) {
			if ( $c instanceof TextNode || $c instanceof Code ) {
				$s .= $c->getLiteral();
			} elseif ( $c instanceof Newline ) {
				$s .= ' ';
			} elseif ( $c instanceof Link && ! self::is_safe_link( $c->getUrl() ) ) {
				$s .= self::unsafe_source( $c );
			} elseif ( ! $c instanceof Image ) {
				$s .= self::plain_raw( $c );
			}
		}
		return $s;
	}

	private static function alt_text( Image $img ): string {
		$s = '';
		foreach ( self::descendants( $img ) as $c ) {
			if ( $c instanceof TextNode || $c instanceof Code ) {
				$s .= $c->getLiteral();
			} elseif ( $c instanceof Newline ) {
				$s .= "\n";
			}
		}
		return $s;
	}

	/** Inline HTML, escaped the way markdown-it escapes (& < > "). */
	public static function inline_html( Node $n ): string {
		$h = '';
		foreach ( self::children( $n ) as $c ) {
			if ( $c instanceof TextNode ) {
				$h .= Text::escape_html( $c->getLiteral() );
			} elseif ( $c instanceof Code ) {
				$h .= '<code>' . Text::escape_html( $c->getLiteral() ) . '</code>';
			} elseif ( $c instanceof Newline ) {
				$h .= Newline::HARDBREAK === $c->getType() ? '<br>' : "\n";
			} elseif ( $c instanceof Emphasis ) {
				$h .= '<em>' . self::inline_html( $c ) . '</em>';
			} elseif ( $c instanceof Strong ) {
				$h .= '<strong>' . self::inline_html( $c ) . '</strong>';
			} elseif ( $c instanceof Image && ! self::is_web_address( $c->getUrl() ) ) {
				$h .= Text::escape_html( self::alt_text( $c ) ); // already an error; never an <img> with a non-web src
			} elseif ( $c instanceof Image ) {
				$title = (string) $c->getTitle();
				$h    .= '<img src="' . Text::escape_html( $c->getUrl() ) . '" alt="' . Text::escape_html( self::alt_text( $c ) ) . '"' . ( '' !== $title ? ' title="' . Text::escape_html( $title ) . '"' : '' ) . ' />';
			} elseif ( $c instanceof Link ) {
				$url = $c->getUrl();
				if ( ! self::is_safe_link( $url ) ) {
					$h .= Text::escape_html( self::unsafe_source( $c ) ); // never a javascript:/data: link: shown as written
					continue;
				}
				$title = (string) $c->getTitle();
				$h    .= '<a href="' . Text::escape_html( $url ) . '"' . ( '' !== $title ? ' title="' . Text::escape_html( $title ) . '"' : '' ) . '>' . self::inline_html( $c ) . '</a>';
			} else {
				$h .= self::inline_html( $c );
			}
		}
		return $h;
	}

	/** An unsafe link, shown as its Markdown source (markdown-it doesn't make it a link at all). */
	private static function unsafe_source( Link $link ): string {
		$title = (string) $link->getTitle();
		return '[' . self::plain_raw( $link ) . '](' . $link->getUrl() . ( '' !== $title ? ' "' . $title . '"' : '' ) . ')';
	}

	/** markdown-it's validateLink: no javascript:, vbscript:, file:, or data: (except a few image types). */
	private static function is_safe_link( string $url ): bool {
		$u = strtolower( trim( $url ) );
		if ( preg_match( '/^(vbscript|javascript|file|data):/', $u ) ) {
			return (bool) preg_match( '/^data:image\/(gif|png|jpeg|webp);/', $u );
		}
		return true;
	}

	/** A complete http(s) address with a real host name (not just "https://"). */
	public static function is_web_address( string $src ): bool {
		if ( ! preg_match( '/^https?:\/\//i', $src ) ) {
			return false;
		}
		$host = namespace\wp_parse_url_compat( $src );
		return '' !== $host && ( str_contains( $host, '.' ) || 'localhost' === $host || preg_match( '/^[\d.]+$|^\[/', $host ) );
	}

	private static function wrap( string $format, string $name, ?array $attrs, string $html ): string {
		if ( 'html' === $format ) {
			return $html;
		}
		$a = $attrs ? ' ' . self::block_attributes( $attrs ) : '';
		return "<!-- wp:{$name}{$a} -->\n{$html}\n<!-- /wp:{$name} -->";
	}

	/** Like core's serialize_block_attributes(): nothing in the JSON can end the comment or open a tag. */
	private static function block_attributes( array $attrs ): string {
		return strtr(
			(string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			array(
				'\\\\' => '\\u005c',
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			)
		);
	}

	/**
	 * Matches the core Image block's saved markup. With an uploaded media item the
	 * block references it by ID; without one it points at the original address.
	 */
	private static function image_block( string $src, string $alt, string $caption, ?array $uploaded, string $format ): string {
		$cap = '' !== $caption ? '<figcaption class="wp-element-caption">' . Text::escape_html( $caption ) . '</figcaption>' : '';
		if ( $uploaded ) {
			$uploaded['sizeSlug'] = (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $uploaded['sizeSlug'] ) );
			$html = '<figure class="wp-block-image size-' . $uploaded['sizeSlug'] . '"><img src="' . Text::escape_html( $uploaded['src'] ) . '" alt="' . Text::escape_html( $alt ) . '" class="wp-image-' . (int) $uploaded['id'] . '"/>' . $cap . '</figure>';
			return self::wrap( $format, 'image', array( 'id' => (int) $uploaded['id'], 'sizeSlug' => $uploaded['sizeSlug'], 'linkDestination' => 'none' ), $html );
		}
		$html = '<figure class="wp-block-image"><img src="' . Text::escape_html( $src ) . '" alt="' . Text::escape_html( $alt ) . '"/>' . $cap . '</figure>';
		return self::wrap( $format, 'image', array( 'linkDestination' => 'none' ), $html );
	}
}

/**
 * Host of a URL like JavaScript's new URL(): lowercase, '' if unparsable.
 * (Outside the class so it has no WordPress dependency.)
 */
function wp_parse_url_compat( string $url ): string {
	$p = parse_url( $url );
	return is_array( $p ) && isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
}
