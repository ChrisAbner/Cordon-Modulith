<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands;

use Cordon\Analysis\Analyzer;
use Cordon\Documentation\DocumentationGenerator;
use Cordon\Documentation\EventInventory;
use Cordon\Laravel\Verifier;
use Cordon\Support\Paths;
use Illuminate\Console\Command;

final class DocsCommand extends Command
{
    protected $signature = 'cordon:docs
        {--output=docs/architecture : Directory for the generated Markdown, relative to the project root}';

    protected $description = 'Generate Markdown documentation of the modules: dependency diagrams, module canvases and an event inventory';

    public function handle(Verifier $verifier, Analyzer $analyzer): int
    {
        $modules = $verifier->modules();

        if (count($modules) === 0) {
            $this->warn('No modules found. Check the resolver settings in config/cordon.php.');

            return self::SUCCESS;
        }

        $output = $this->option('output');
        $directory = Paths::join($verifier->basePath(), is_string($output) && $output !== '' ? $output : 'docs/architecture');

        $snapshot = $analyzer->snapshot($modules);
        $result = $analyzer->check($snapshot, $verifier->basePath());
        $files = (new DocumentationGenerator)->generate($snapshot, $result->violations, EventInventory::build($snapshot), $verifier->basePath());

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
