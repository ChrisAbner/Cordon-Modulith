<?php

declare(strict_types=1);

namespace Cordon\Tests\Oracle;

use Composer\InstalledVersions;
use Cordon\Analysis\PhpParserExtractor;
use Cordon\Module\Module;
use Cordon\Module\ModuleMap;

/**
 * Runs the position matrix through the extractor: each cell must give
 * exactly its expected targets under Matrix\Target, each of them owned by
 * module [Target] (so a case-sensitive module lookup, bug #26, fails too).
 */
final class MatrixChecker
{
    /**
     * @param  list<Cell>  $cells
     * @return array{failures: list<string>, skipped: list<string>}
     */
    public static function check(array $cells, string $directory): array
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $parser = (string) InstalledVersions::getVersion('nikic/php-parser');
        $extractor = new PhpParserExtractor;
        $modules = new ModuleMap([Module::make('Target', 'Matrix\\Target', '/nowhere/Target')]);
        $failures = [];
        $skipped = [];

        foreach ($cells as $cell) {
            if (version_compare($parser, $cell->parserMin, '<')) {
                $skipped[] = sprintf('%s (needs nikic/php-parser %s)', $cell->id(), $cell->parserMin);

                continue;
            }

            $file = $directory.'/'.$cell->file();
            file_put_contents($file, $cell->code);
            $analysis = $extractor->extract($file);

            if ($analysis->error !== null) {
                $failures[] = sprintf('%s: parse error (%s)', $cell->id(), $analysis->error);

                continue;
            }

            $found = [];

            foreach ($analysis->references as $reference) {
                $target = strtolower($reference->target);

                if (! str_starts_with($target, 'matrix\\target\\')) {
                    continue;
                }

                $found[$target] = true;

                if ($modules->forClass($reference->target)?->name !== 'Target') {
                    $failures[] = sprintf('%s: %s is not attributed to module [Target]', $cell->id(), $reference->target);
                }
            }

            $found = array_keys($found);
            sort($found);

            if ($found !== $cell->expected) {
                $failures[] = sprintf('%s: expected [%s], found [%s]', $cell->id(), implode(', ', $cell->expected), implode(', ', $found));
            }
        }

        return ['failures' => array_values(array_unique($failures)), 'skipped' => $skipped];
    }

    /**
     * Failures grouped by position, form and container, most common first.
     *
     * @param  list<string>  $failures
     * @return array<string, int>
     */
    public static function families(array $failures): array
    {
        $families = [];

        foreach ($failures as $failure) {
            [$position, $form, $container] = explode(' ', strtok($failure, ':') ?: '') + ['', '', ''];

            foreach (['position '.$position, 'form '.$form, 'container '.$container] as $family) {
                $families[$family] = ($families[$family] ?? 0) + 1;
            }
        }

        arsort($families);

        return $families;
    }
}
