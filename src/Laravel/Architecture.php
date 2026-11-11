<?php

declare(strict_types=1);

namespace Cordon\Laravel;

use Cordon\Analysis\Analyzer;
use Cordon\Documentation\GraphExporter;
use Cordon\Support\ConfigValidator;
use Illuminate\Container\Container;
use InvalidArgumentException;
use LogicException;

/**
 * The module graph and the module documentation of cordon:graph and
 * cordon:docs, as text, for tools that show them without running a command
 * (a dashboard, an admin panel). Check Cordon::configurationErrors() first:
 * the answers describe the config as it is.
 *
 * @api
 */
final class Architecture
{
    /**
     * The Mermaid flowchart of cordon:graph, without the code fence: one
     * arrow per dependency labelled with its references, red when it has
     * violations. With $module, only that module and its neighbours.
     *
     * @throws InvalidArgumentException When $module is not a module.
     */
    public static function graph(?string $module = null): string
    {
        $verifier = self::verifier();

        if ($module !== null && $verifier->modules()->get($module) === null) {
            throw new InvalidArgumentException(sprintf('Unknown module [%s].%s', $module, ConfigValidator::didYouMean($module, $verifier->modules()->names())));
        }

        $snapshot = $verifier->snapshot();

        return GraphExporter::flowchart(GraphExporter::model(
            $verifier->modules()->names(),
            $snapshot->context->edges,
            $verifier->check($snapshot)->violations,
            $module,
        ));
    }

    /**
     * The Markdown files cordon:docs writes, by file name: README.md,
     * modules/<Module>.md and events.md, exactly as the command writes them: nothing
     * is written to disk, the code outside the modules is not analysed, and the
     * violations are the ones in the code, whatever the baseline says.
     *
     * @return array<string, string>
     */
    public static function docs(): array
    {
        return ModuleDocs::files(self::verifier(), Container::getInstance()->make(Analyzer::class));
    }

    private static function verifier(): Verifier
    {
        $container = class_exists(Container::class) ? Container::getInstance() : null;

        if ($container === null || ! $container->bound(Verifier::class)) {
            throw new LogicException('Cordon Modulith needs a booted Laravel application.');
        }

        return $container->make(Verifier::class);
    }
}
