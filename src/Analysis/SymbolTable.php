<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * Every class declared in the analysed modules, looked up case-insensitively like PHP does.
 */
final readonly class SymbolTable
{
    /**
     * @param  array<string, ClassDeclaration>  $classes
     */
    private function __construct(private array $classes) {}

    /**
     * @param  iterable<FileAnalysis>  $analyses
     */
    public static function fromAnalyses(iterable $analyses): self
    {
        $classes = [];

        foreach ($analyses as $analysis) {
            foreach ($analysis->declarations as $declaration) {
                $classes[strtolower($declaration->fqcn)] = $declaration;
            }
        }

        return new self($classes);
    }

    public function get(string $fqcn): ?ClassDeclaration
    {
        return $this->classes[strtolower(ltrim($fqcn, '\\'))] ?? null;
    }

    public function count(): int
    {
        return count($this->classes);
    }
}
