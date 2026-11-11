<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Analysis\Analyzer;
use Cordon\Documentation\DocumentationGenerator;
use Cordon\Documentation\EventInventory;

/**
 * The files of cordon:docs, shared by the command and Architecture::docs() so
 * that both document the same thing: the modules only (the code outside them
 * is not analysed), with every violation, whatever the baseline says.
 *
 * @internal
 */
final class ModuleDocs
{
    /**
     * @return array<string, string> file name => Markdown
     */
    public static function files(Verifier $verifier, Analyzer $analyzer): array
    {
        $snapshot = $analyzer->snapshot($verifier->modules());
        $result = $analyzer->check($snapshot, $verifier->basePath());

        return (new DocumentationGenerator)->generate($snapshot, $result->violations, EventInventory::build($snapshot), $verifier->basePath());
    }
}
