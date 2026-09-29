<?php

namespace Acme\Media\Storage;

use Acme\Media\Contracts\StoresImages;

final class S3Storage implements StoresImages
{
    public function store(string $path): string
    {
        return $path;
    }
}
