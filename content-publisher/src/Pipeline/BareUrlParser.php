<?php
/**
 * Turns bare web addresses into links while parsing, so that characters inside
 * an address (like _ or *) are never read as emphasis. Only addresses starting
 * with http://, https:// or www. are linked, not every "example.com" or
 * "Node.js" in a sentence (same rule as the prototype).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use CPub\Publisher\Vendor\League\CommonMark\Node\Inline\Text as TextNode;
use CPub\Publisher\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use CPub\Publisher\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use CPub\Publisher\Vendor\League\CommonMark\Parser\InlineParserContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BareUrlParser implements InlineParserInterface {

	public function getMatchDefinition(): InlineParserMatch {
		return InlineParserMatch::oneOf( 'http://', 'https://', 'www.' );
	}

	public function parse( InlineParserContext $context ): bool {
		$cursor = $context->getCursor();
		$prev   = $cursor->peek( -1 );
		if ( null !== $prev && preg_match( '/[A-Za-z0-9_]/', $prev ) ) {
			return false; // mid-word, like "xhttp://"
		}
		$url = BareUrl::match_at( $cursor->getRemainder() );
		if ( null === $url ) {
			return false;
		}
		$cursor->advanceBy( mb_strlen( $url, 'UTF-8' ) );
		$href = str_starts_with( strtolower( $url ), 'www.' ) ? 'https://' . $url : $url;
		$link = new Link( MarkdownToPost::normalize_link( $href ), $url );
		$link->data->set( 'bare', true );
		$context->getContainer()->appendChild( $link );
		return true;
	}
}
