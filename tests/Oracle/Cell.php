<?php

declare(strict_types=1);

namespace Cordon\Tests\Oracle;

/**
 * One case of the position matrix: a class name written in one form, at one
 * position, inside one container, and the targets the extractor must find.
 */
final readonly class Cell
{
    /**
     * @param  list<string>  $expected  Lowercased targets under Matrix\Target.
     * @param  bool  $docblocks  Whether the extractor runs with the docblocks option on.
     */
    public function __construct(
        public string $position,
        public string $form,
        public string $container,
        public string $code,
        public array $expected,
        public string $phpMin,
        public string $parserMin,
        public bool $declaration,
        public bool $docblocks = false,
    ) {}

    public function id(): string
    {
        return $this->position.' '.$this->form.' '.$this->container;
    }

    public function file(): string
    {
        return str_replace(' ', '.', $this->id()).'.php';
    }
}
