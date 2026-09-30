<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 * (c) Rezo Zero / Ambroise Maupate
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote;

use CPub\Publisher\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use CPub\Publisher\Vendor\League\CommonMark\Event\DocumentParsedEvent;
use CPub\Publisher\Vendor\League\CommonMark\Extension\ConfigurableExtensionInterface;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Event\AnonymousFootnotesListener;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Event\FixOrphanedFootnotesAndRefsListener;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Event\GatherFootnotesListener;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Event\NumberFootnotesListener;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Node\Footnote;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Node\FootnoteBackref;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Node\FootnoteContainer;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Parser\AnonymousFootnoteRefParser;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Parser\FootnoteRefParser;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Parser\FootnoteStartParser;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Renderer\FootnoteBackrefRenderer;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Renderer\FootnoteContainerRenderer;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Renderer\FootnoteRefRenderer;
use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Renderer\FootnoteRenderer;
use CPub\Publisher\Vendor\League\Config\ConfigurationBuilderInterface;
use CPub\Publisher\Vendor\Nette\Schema\Expect;

final class FootnoteExtension implements ConfigurableExtensionInterface
{
    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        $builder->addSchema('footnote', Expect::structure([
            'backref_class' => Expect::string('footnote-backref'),
            'backref_symbol' => Expect::string('↩'),
            'container_add_hr' => Expect::bool(true),
            'container_class' => Expect::string('footnotes'),
            'enable_inline_footnotes' => Expect::bool(true),
            'ref_class' => Expect::string('footnote-ref'),
            'ref_id_prefix' => Expect::string('fnref:'),
            'footnote_class' => Expect::string('footnote'),
            'footnote_id_prefix' => Expect::string('fn:'),
        ]));
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        if ($environment->getConfiguration()->get('footnote/enable_inline_footnotes')) {
            $environment->addInlineParser(new AnonymousFootnoteRefParser(), 35);
            $environment->addEventListener(DocumentParsedEvent::class, [new AnonymousFootnotesListener(), 'onDocumentParsed'], 40);
        }

        $environment->addBlockStartParser(new FootnoteStartParser(), 51);
        $environment->addInlineParser(new FootnoteRefParser(), 51);

        $environment->addRenderer(FootnoteContainer::class, new FootnoteContainerRenderer());
        $environment->addRenderer(Footnote::class, new FootnoteRenderer());
        $environment->addRenderer(FootnoteRef::class, new FootnoteRefRenderer());
        $environment->addRenderer(FootnoteBackref::class, new FootnoteBackrefRenderer());

        $environment->addEventListener(DocumentParsedEvent::class, [new FixOrphanedFootnotesAndRefsListener(), 'onDocumentParsed'], 30);
        $environment->addEventListener(DocumentParsedEvent::class, [new NumberFootnotesListener(), 'onDocumentParsed'], 20);
        $environment->addEventListener(DocumentParsedEvent::class, [new GatherFootnotesListener(), 'onDocumentParsed'], 10);
    }
}
