<?php

declare(strict_types=1);

namespace Cordon\Analysis;

final readonly class FileAnalysis
{
    /**
     * @param  list<ClassDeclaration>  $declarations
     * @param  list<Reference>  $references
     */
    public function __construct(
        public string $file,
        public array $declarations = [],
        public array $references = [],
        public ?string $error = null,
    ) {}
}
