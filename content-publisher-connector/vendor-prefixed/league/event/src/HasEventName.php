<?php

declare(strict_types=1);

namespace CPub\Connector\Vendor\League\Event;

interface HasEventName
{
    public function eventName(): string;
}
