<?php

/*
 * Runs the position matrix without Pest, so the parser-floor job (no dev
 * dependencies) can run it too.
 *
 *   php tests/Oracle/check-matrix.php [--src=<copy of src/>]
 *
 * --src loads Cordon from another directory: the calibration runs the
 * matrix against copies of src/ with known bugs put back.
 */

declare(strict_types=1);

$options = getopt('', ['src:']);

if (isset($options['src']) && is_string($options['src'])) {
    $src = rtrim($options['src'], '/');
    spl_autoload_register(static function (string $class) use ($src): void {
        if (str_starts_with($class, 'Cordon\\') && ! str_starts_with($class, 'Cordon\\Tests\\')) {
            $file = $src.'/'.str_replace('\\', '/', substr($class, strlen('Cordon\\'))).'.php';

            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/Cell.php';
require_once __DIR__.'/PositionMatrix.php';
require_once __DIR__.'/MatrixChecker.php';

use Composer\InstalledVersions;
use Cordon\Tests\Oracle\MatrixChecker;
use Cordon\Tests\Oracle\PositionMatrix;

$cells = PositionMatrix::cells();
$directory = sys_get_temp_dir().'/cordon-matrix-'.getmypid();
['failures' => $failures, 'skipped' => $skipped] = MatrixChecker::check($cells, $directory);

printf("Position matrix: %d cells, %d failed, %d skipped (nikic/php-parser %s, PHP %s)\n", count($cells), count($failures), count($skipped), InstalledVersions::getPrettyVersion('nikic/php-parser'), PHP_VERSION);

foreach (array_slice(MatrixChecker::families($failures), 0, 10, true) as $family => $count) {
    printf("  %4d  %s\n", $count, $family);
}

foreach (array_slice($failures, 0, 30) as $failure) {
    echo '  - ', $failure, PHP_EOL;
}

exit($failures === [] ? 0 : 1);
