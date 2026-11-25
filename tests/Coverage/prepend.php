<?php

declare(strict_types=1);
use Pest\Mutate\Support\StreamWrapper;

/*
 * Prepended to the PHP processes the test suite starts while it collects
 * coverage or runs a mutant (see SubprocessCoverage).
 */

(static function (): void {
    // The mutant: Pest's stream wrapper serves the mutated file in place of
    // the original, as it does in the test process.
    $original = getenv('PEST_MUTATION_TESTING');

    if ($original !== false) {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        StreamWrapper::start($original, (string) getenv('PEST_MUTATION_FILE'));
    }

    $directory = getenv('CORDON_SUBPROCESS_COVERAGE');

    if ($directory === false || ! is_dir($directory) || ! extension_loaded('pcov')) {
        return;
    }

    pcov\start();

    // Registered from a shutdown function, so that it runs after the ones the
    // process registers itself and their code is covered too.
    register_shutdown_function(static function () use ($directory): void {
        register_shutdown_function(static function () use ($directory): void {
            pcov\stop();
            $files = pcov\waiting();

            if ($files === []) {
                return;
            }

            $lines = pcov\collect(pcov\inclusive, $files);
            file_put_contents(sprintf('%s/%d-%s.json', $directory, getmypid(), bin2hex(random_bytes(4))), json_encode($lines, JSON_THROW_ON_ERROR));
        });
    });
})();
