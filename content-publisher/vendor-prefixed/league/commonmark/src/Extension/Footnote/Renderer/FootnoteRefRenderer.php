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

namespace CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Renderer;

use CPub\Publisher\Vendor\League\CommonMark\Extension\Footnote\Node\FootnoteRef;
use CPub\Publisher\Vendor\League\CommonMark\Node\Node;
use CPub\Publisher\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CPub\Publisher\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CPub\Publisher\Vendor\League\CommonMark\Util\HtmlElement;
use CPub\Publisher\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use CPub\Publisher\Vendor\League\Config\ConfigurationAwareInterface;
use CPub\Publisher\Vendor\League\Config\ConfigurationInterface;

final class FootnoteRefRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{
    private ConfigurationInterface $config;

    /**
     * @param FootnoteRef $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        FootnoteRef::assertInstanceOf($node);

        $attrs = $node->data->getData('attributes');
        $attrs->append('class', $this->config->get('footnote/ref_class'));
        // The configured prefix is emitted verbatim in the target's id, so only lowercase the label.
        $destination = $node->getReference()->getDestination();
        $hrefPrefix  = '#' . $this->config->get('footnote/footnote_id_prefix');
        $href        = \strncmp($destination, $hrefPrefix, \strlen($hrefPrefix)) === 0
            ? $hrefPrefix . \mb_strtolower(\substr($destination, \strlen($hrefPrefix)), 'UTF-8')
            : \mb_strtolower($destination, 'UTF-8');
        $attrs->set('href', $href);
        $attrs->set('role', 'doc-noteref');

        $idPrefix = $this->config->get('footnote/ref_id_prefix');

        return new HtmlElement(
            'sup',
            [
                'id' => $idPrefix . \mb_strtolower($node->getReference()->getLabel(), 'UTF-8'),
            ],
            new HtmlElement(
                'a',
                $attrs->export(),
                $node->getReference()->getTitle()
            ),
            true
        );
    }

    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }

    public function getXmlTagName(Node $node): string
    {
        return 'footnote_ref';
    }

    /**
     * @param FootnoteRef $node
     *
     * @return array<string, scalar>
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function getXmlAttributes(Node $node): array
    {
        FootnoteRef::assertInstanceOf($node);

        return [
            'reference' => $node->getReference()->getLabel(),
        ];
    }
}
