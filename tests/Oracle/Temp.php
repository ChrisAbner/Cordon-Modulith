<?php

declare(strict_types=1);

namespace Cordon\Tests\Oracle;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Temporary files and directories of the tests and oracles, removed when
 * the process ends, so runs do not pile up in the system temp directory.
 */
final class Temp
{
    /** @var list<string> */
    private static array $paths = [];

    /**
     * A new, empty directory under the system temp directory, removed at exit.
     */
    public static function directory(string $prefix): string
    {
        $directory = sys_get_temp_dir().'/'.$prefix.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);

        return self::removeAtExit($directory);
    }

    public static function removeAtExit(string $path): string
    {
        if (self::$paths === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$paths as $path) {
                    self::remove($path);
                }
            });
        }

        self::$paths[] = $path;

        return $path;
    }

    public static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
