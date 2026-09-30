<?php

declare(strict_types=1);

namespace CPub\Connector\Vendor\League\Event;

use CPub\Connector\Vendor\Psr\EventDispatcher\EventDispatcherInterface;

interface EventDispatchingListenerRegistry extends ListenerRegistry, EventDispatcherInterface
{
}
