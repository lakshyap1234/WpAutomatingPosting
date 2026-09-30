<?php
/**
 * CommonMark without raw HTML: the HTML block and inline parsers are not
 * registered at all, so "<div>" stays literal text (markdown-it's html:false).
 * Adds bare-URL linking. Configuration schema is borrowed from the core
 * extension, whose parsers read it.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

use CPub\Publisher\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Delimiter\Processor\EmphasisDelimiterProcessor;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block as CoreBlock;
use CPub\Publisher\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline as CoreInline;
use CPub\Publisher\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CPub\Publisher\Vendor\League\CommonMark\Parser\Inline\NewlineParser;
use CPub\Publisher\Vendor\League\Config\ConfigurationBuilderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SafeMarkdownExtension implements ConfigurableExtensionInterface {

	public function configureSchema( ConfigurationBuilderInterface $builder ): void {
		( new CommonMarkCoreExtension() )->configureSchema( $builder );
	}

	public function register( EnvironmentBuilderInterface $env ): void {
		$env->addBlockStartParser( new CoreBlock\BlockQuoteStartParser(), 70 )
			->addBlockStartParser( new CoreBlock\HeadingStartParser(), 60 )
			->addBlockStartParser( new CoreBlock\FencedCodeStartParser(), 50 )
			->addBlockStartParser( new CoreBlock\ThematicBreakStartParser(), 20 )
			->addBlockStartParser( new CoreBlock\ListBlockStartParser(), 10 )
			->addBlockStartParser( new CoreBlock\IndentedCodeStartParser(), -100 )
			->addInlineParser( new NewlineParser(), 200 )
			->addInlineParser( new CoreInline\BacktickParser(), 150 )
			->addInlineParser( new BareUrlParser(), 100 )
			->addInlineParser( new CoreInline\EscapableParser(), 80 )
			->addInlineParser( new CoreInline\EntityParser(), 70 )
			->addInlineParser( new CoreInline\AutolinkParser(), 50 )
			->addInlineParser( new CoreInline\CloseBracketParser(), 30 )
			->addInlineParser( new CoreInline\OpenBracketParser(), 20 )
			->addInlineParser( new CoreInline\BangParser(), 10 )
			->addDelimiterProcessor( new EmphasisDelimiterProcessor( '*' ) )
			->addDelimiterProcessor( new EmphasisDelimiterProcessor( '_' ) );
	}
}
