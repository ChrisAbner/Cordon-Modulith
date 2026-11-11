<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Laravel\ModuleDocs;
use Cordon\Laravel\Verifier;
use Cordon\Laravel\XdebugWarning;
use Cordon\Support\Paths;
use Illuminate\Console\Command;

/**
 * @internal
 */
final class DocsCommand extends Command
{
    protected $signature = 'cordon:docs
        {--output=docs/architecture : Directory for the generated Markdown, relative to the project root}
        {--diagrams=mermaid : Diagrams to generate: mermaid, or c4 to also write architecture.puml (C4-PlantUML)}';

    protected $description = 'Generate Markdown documentation of the modules: dependency diagrams, module canvases and an event inventory';

    public function handle(Verifier $verifier, XdebugWarning $xdebug): int
    {
        $diagrams = $this->option('diagrams');

        if (! in_array($diagrams, ['mermaid', 'c4'], true)) {
            $this->output->getErrorStyle()->writeln(sprintf('<error>Unknown diagrams [%s]. Use mermaid or c4.</error>', is_string($diagrams) ? $diagrams : ''));

            return self::INVALID;
        }

        if (($message = $xdebug->message()) !== null) {
            $this->output->getErrorStyle()->writeln('<comment>'.$message.'</comment>');
        }

        $modules = $verifier->modules();

        if (count($modules) === 0) {
            $this->warn('No modules found. Check the resolver settings in config/cordon.php.');

            return self::SUCCESS;
        }

        $output = $this->option('output');
        $directory = Paths::join($verifier->basePath(), is_string($output) && $output !== '' ? $output : 'docs/architecture');

        $files = ModuleDocs::files($verifier, $diagrams);

        foreach ($files as $name => $contents) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0777, true) && ! is_dir(dirname($path))) {
                $this->error(sprintf('Unable to create the directory [%s].', dirname($path)));

                return self::FAILURE;
            }

            file_put_contents($path, $contents);
        }

        $this->info(sprintf(
            'Documented %d modules in %s (%d files).',
            count($modules),
            Paths::relative($verifier->basePath(), $directory),
            count($files),
        ));

        return self::SUCCESS;
    }
}
