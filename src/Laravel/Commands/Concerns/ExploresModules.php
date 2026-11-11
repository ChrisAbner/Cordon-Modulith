<?php

declare(strict_types=1);

namespace Cordon\Laravel\Commands\Concerns;

use Cordon\Laravel\Verifier;
use Cordon\Module\Module;
use Cordon\Support\ConfigValidator;

/**
 * Shared by the commands that answer questions about the modules
 * (explain, why, impact, graph): format and config checks, module lookup,
 * and errors on stderr so that --format=json output stays JSON.
 *
 * @internal
 */
trait ExploresModules
{
    /**
     * Prints an error on stderr when --format is not one of $formats.
     *
     * @param  list<string>  $formats
     */
    private function formatFailed(array $formats = ['text', 'json']): bool
    {
        $format = $this->option('format');

        if (in_array($format, $formats, true)) {
            return false;
        }

        $last = array_pop($formats);
        $this->stderr(sprintf('Unknown format [%s]. Use %s or %s.', is_string($format) ? $format : '', implode(', ', $formats), $last));

        return true;
    }

    private function stderr(string $message): void
    {
        $this->output->getErrorStyle()->writeln('<error>'.$message.'</error>');
    }

    /**
     * Prints the config errors, if any: the answers would describe a config
     * that does not do what it says.
     */
    private function configurationFailed(Verifier $verifier): bool
    {
        $errors = $verifier->configurationErrors();

        foreach ($errors as $error) {
            $this->stderr($error);
        }

        return $errors !== [];
    }

    private function module(Verifier $verifier, string $name): ?Module
    {
        $module = $verifier->modules()->get($name);

        if ($module === null) {
            $this->stderr(sprintf('Unknown module [%s].%s', $name, ConfigValidator::didYouMean($name, $verifier->modules()->names())));
        }

        return $module;
    }

    private function text(string $argument): string
    {
        $value = $this->argument($argument);

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): void
    {
        $this->output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
