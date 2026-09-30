<?php

declare(strict_types=1);

namespace CPub\Connector\Vendor\League\Event;

interface Listener
{
    public function __invoke(object $event): void;
}
