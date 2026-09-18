<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class RecordingSharedEventManager
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public $attached = [];

    public function attach($identifier, $event, $listener, $priority = 1): void
    {
        $this->attached[] = [
            'identifier' => $identifier,
            'event' => $event,
            'listener' => $listener,
            'priority' => $priority,
        ];
    }
}
