<?php

declare(strict_types=1);

namespace Cordon\Support;

use DirectoryIterator;

final class Paths
{
    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    public static function join(string $base, string $path): string
    {
        if (self::isAbsolute($path)) {
            return rtrim($path, '/\\');
        }

        return rtrim($base, '/\\').DIRECTORY_SEPARATOR.trim($path, '/\\');
    }

    /**
     * A forward-slash path relative to $basePath, or the normalized path when outside it.
     */
    public static function relative(?string $basePath, ?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $file = str_replace('\\', '/', $file);

        if ($basePath === null) {
            return $file;
        }

        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * Direct, non-hidden sub-directories of $path, sorted.
     *
     * @return list<string>
     */
    public static function childDirectories(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $directories = [];

        foreach (new DirectoryIterator($path) as $item) {
            if ($item->isDot() || ! $item->isDir() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }

            $directories[] = $item->getPathname();
        }

        sort($directories);

        return $directories;
    }
}
