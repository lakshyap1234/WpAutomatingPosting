<?php

declare(strict_types=1);

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com> and uAfrica.com (http://uafrica.com)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CPub\Publisher\Vendor\League\CommonMark\Extension\Strikethrough;

use CPub\Publisher\Vendor\League\CommonMark\Node\Node;
use CPub\Publisher\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use CPub\Publisher\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use CPub\Publisher\Vendor\League\CommonMark\Util\HtmlElement;
use CPub\Publisher\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;

final class StrikethroughRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @param Strikethrough $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Strikethrough::assertInstanceOf($node);

        return new HtmlElement('del', $node->data->get('attributes'), $childRenderer->renderNodes($node->children()));
    }

    public function getXmlTagName(Node $node): string
    {
        return 'strikethrough';
    }

    /**
     * {@inheritDoc}
     */
    public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
