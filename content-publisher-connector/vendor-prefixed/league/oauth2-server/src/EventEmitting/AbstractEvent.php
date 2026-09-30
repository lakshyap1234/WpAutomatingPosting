<?php

declare(strict_types=1);

namespace CPub\Connector\Vendor\League\OAuth2\Server\EventEmitting;

use CPub\Connector\Vendor\League\Event\HasEventName;
use CPub\Connector\Vendor\Psr\EventDispatcher\StoppableEventInterface;

class AbstractEvent implements StoppableEventInterface, HasEventName
{
    private bool $propagationStopped = false;

    public function __construct(private string $name)
    {
    }

    public function eventName(): string
    {
        return $this->name;
    }

    /**
     * Backwards compatibility method
     *
     * @deprecated use eventName instead
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): self
    {
        $this->propagationStopped = true;

        return $this;
    }
}
