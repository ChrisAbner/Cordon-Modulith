<?php

declare(strict_types=1);

namespace Cordon\Documentation;

final readonly class EventEntry
{
    /**
     * @param  list<array{module: string, class: string|null, file: string, line: int}>  $publishers
     * @param  list<array{module: string, class: string|null, file: string, line: int}>  $listeners
     */
    public function __construct(
        public string $fqcn,
        public string $module,
        public array $publishers,
        public array $listeners,
    ) {}
}
