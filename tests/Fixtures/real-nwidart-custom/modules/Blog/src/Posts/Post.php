<?php

namespace Acme\Blog\Posts;

use Acme\Media\Contracts\StoresImages;
use Acme\Media\Storage\S3Storage;

final class Post
{
    public function __construct(private StoresImages $images) {}

    public function legacyStorage(): S3Storage
    {
        return new S3Storage;
    }
}
