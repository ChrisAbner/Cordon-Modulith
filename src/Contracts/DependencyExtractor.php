<?php

declare(strict_types=1);

namespace Cordon\Contracts;

use Cordon\Analysis\FileAnalysis;

/**
 * Reads one PHP file and returns the classes it declares and the classes it references.
 * Implementations must never load or execute the analysed code.
 */
interface DependencyExtractor
{
    public function extract(string $file): FileAnalysis;
}
