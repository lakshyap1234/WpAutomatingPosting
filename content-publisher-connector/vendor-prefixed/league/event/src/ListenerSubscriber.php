<?php

declare(strict_types=1);

namespace CPub\Connector\Vendor\League\Event;

interface ListenerSubscriber
{
    public function subscribeListeners(ListenerRegistry $acceptor): void;
}
