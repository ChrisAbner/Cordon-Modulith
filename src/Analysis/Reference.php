<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * One usage of a class name inside a file (new, static call, type, extends, attribute...).
 *
 * @api
 */
final readonly class Reference
{
    /** Any reference that is not a listener's event. */
    public const USE = 'use';

    /** The event a listener handles: handle(Event $e) or Event::listen(Event::class, ...). */
    public const LISTENER = 'listener';

    /**
     * @param  self::USE|self::LISTENER  $kind
     * @param  bool  $relation  The model of an Eloquent relation: $this->belongsTo(Target::class) and its siblings.
     * @param  ?string  $signature  In the public signature of $sourceClass, the member that names it: "find() returns", "pay() takes", "$customer has type", "DEFAULT has type".
     */
    public function __construct(
        public string $sourceFile,
        public ?string $sourceClass,
        public string $target,
        public int $line,
        public string $kind = self::USE,
        public bool $relation = false,
        public ?string $signature = null,
    ) {}
}
