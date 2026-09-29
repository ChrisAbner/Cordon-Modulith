<?php

use Cordon\Laravel\XdebugWarning;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * @return array{0: ConsoleOutput, 1: Closure(): string, 2: Closure(): string}
 */
function split_output(): array
{
    $stdout = '';
    $stderr = fopen('php://memory', 'w+');

    $output = new class(function (string $message) use (&$stdout): void {
        $stdout .= $message;
    }) extends ConsoleOutput
    {
        public function __construct(private readonly Closure $sink)
        {
            parent::__construct(self::VERBOSITY_NORMAL, false);
        }

        protected function doWrite(string $message, bool $newline): void
        {
            ($this->sink)($message.($newline ? PHP_EOL : ''));
        }
    };
    $output->setErrorOutput(new StreamOutput($stderr, StreamOutput::VERBOSITY_NORMAL, false));

    return [
        $output,
        function () use (&$stdout): string {
            return $stdout;
        },
        function () use ($stderr): string {
            rewind($stderr);

            return (string) stream_get_contents($stderr);
        },
    ];
}

it('is active when Xdebug is loaded with a mode other than off', function () {
    expect((new XdebugWarning(true, 'develop'))->isActive())->toBeTrue()
        ->and((new XdebugWarning(true, 'debug,develop'))->message())->toContain('XDEBUG_MODE=off');
});

it('is inactive when the mode is off or empty', function () {
    expect((new XdebugWarning(true, 'off'))->isActive())->toBeFalse()
        ->and((new XdebugWarning(true, ' OFF '))->isActive())->toBeFalse()
        ->and((new XdebugWarning(true, ''))->message())->toBeNull();
});

it('is inactive when Xdebug is not loaded', function () {
    expect((new XdebugWarning(false, 'develop'))->isActive())->toBeFalse();
});

it('lets the XDEBUG_MODE environment variable override the ini setting', function () {
    $previous = getenv('XDEBUG_MODE');
    $ini = ini_get('xdebug.mode');

    try {
        putenv('XDEBUG_MODE=off');
        expect((new XdebugWarning(true))->isActive())->toBeFalse();

        putenv('XDEBUG_MODE=develop');
        expect((new XdebugWarning(true))->isActive())->toBeTrue();
    } finally {
        $previous === false ? putenv('XDEBUG_MODE') : putenv('XDEBUG_MODE='.$previous);
    }

    expect($ini)->toBe(ini_get('xdebug.mode'));
});

it('warns on stderr and keeps the JSON on stdout valid', function () {
    app()->instance(XdebugWarning::class, new XdebugWarning(true, 'develop'));
    [$output, $stdout, $stderr] = split_output();

    $exitCode = Artisan::call('cordon:verify', ['--format' => 'json'], $output);
    $report = json_decode($stdout(), true);

    expect($exitCode)->toBe(1)
        ->and($report)->toBeArray()
        ->and($stderr())->toContain('Xdebug is enabled')
        ->and($stdout())->not->toContain('Xdebug');
});

it('does not warn when Xdebug is off', function () {
    app()->instance(XdebugWarning::class, new XdebugWarning(true, 'off'));
    [$output, $stdout, $stderr] = split_output();

    Artisan::call('cordon:verify', ['--format' => 'json'], $output);

    expect($stderr())->toBe('')
        ->and(json_decode($stdout(), true))->toBeArray();
});

it('warns on stderr when generating the documentation', function () {
    app()->instance(XdebugWarning::class, new XdebugWarning(true, 'develop'));
    [$output, $stdout, $stderr] = split_output();
    $directory = sys_get_temp_dir().'/cordon-docs-xdebug-'.uniqid();

    Artisan::call('cordon:docs', ['--output' => $directory], $output);

    expect($stderr())->toContain('Xdebug is enabled')
        ->and($stdout())->not->toContain('Xdebug');

    foreach ([...glob($directory.'/modules/*.md'), ...glob($directory.'/*.md')] as $file) {
        unlink($file);
    }

    rmdir($directory.'/modules');
    rmdir($directory);
});
