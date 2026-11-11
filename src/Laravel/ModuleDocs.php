<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Analysis\Analyzer;
use Cordon\Documentation\DocumentationGenerator;
use Cordon\Documentation\EventInventory;
use Cordon\Documentation\GraphExporter;

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
     * The Markdown files, plus architecture.puml (the C4 diagram) with $diagrams = 'c4'.
     *
     * @param  'mermaid'|'c4'  $diagrams
     * @return array<string, string> file name => contents
     */
    public static function files(Verifier $verifier, Analyzer $analyzer, string $diagrams = 'mermaid'): array
    {
        $snapshot = $analyzer->snapshot($verifier->modules());
        $result = $analyzer->check($snapshot, $verifier->basePath());

        $files = (new DocumentationGenerator)->generate($snapshot, $result->violations, EventInventory::build($snapshot), $verifier->basePath());

        if ($diagrams === 'c4') {
            $files['architecture.puml'] = GraphExporter::c4($snapshot, $result->violations);
        }

        return $files;
    }
}
