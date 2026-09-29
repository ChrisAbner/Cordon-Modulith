<?php

declare(strict_types=1);

namespace Cordon\Documentation;

/**
 * A place where an event class is dispatched or listened to.
 */
final readonly class EventUsage
{
    public const DISPATCH = 'dispatch';

    public const LISTEN = 'listen';

    /**
     * @param  self::DISPATCH|self::LISTEN  $kind
     * @param  bool  $explicit  True when the code makes clear the class is an event
     *                          (event(new X), Event::dispatch, Event::listen), false for
     *                          X::dispatch() and handle(X $x), which jobs also use.
     */
    public function __construct(
        public string $kind,
        public string $event,
        public ?string $sourceClass,
        public string $file,
        public int $line,
        public bool $explicit,
    ) {}
}
