<?php

declare(strict_types=1);

namespace Cordon\Analysis;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lists the PHP files of a module, skipping excluded directory names and Blade views.
 */
final readonly class FileCollector
{
    /**
     * @param  list<string>  $excludeDirectories  Directory names skipped at any depth.
     */
    public function __construct(private array $excludeDirectories = ['vendor', 'node_modules']) {}

    /**
     * @return list<string>
     */
    public function collect(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $excluded = array_flip($this->excludeDirectories);

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($excluded): bool {
                if ($current->isDir()) {
                    return ! isset($excluded[$current->getFilename()]) && ! str_starts_with($current->getFilename(), '.');
                }

                return $current->getExtension() === 'php'
                    && ! str_ends_with($current->getFilename(), '.blade.php');
            },
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
