<?php

declare(strict_types=1);

namespace Cordon\Laravel;

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
     * The files are read through the verifier's analyzer, so they use the same analysis cache as cordon:verify.
     *
     * @param  'mermaid'|'c4'  $diagrams
     * @return array<string, string> file name => contents
     */
    public static function files(Verifier $verifier, string $diagrams = 'mermaid'): array
    {
        $snapshot = $verifier->moduleSnapshot();
        $result = $verifier->check($snapshot, baseline: false);

        $files = (new DocumentationGenerator)->generate($snapshot, $result->violations, EventInventory::build($snapshot), $verifier->basePath());

        if ($diagrams === 'c4') {
            $files['architecture.puml'] = GraphExporter::c4($snapshot, $result->violations);
        }

        return $files;
    }
}
