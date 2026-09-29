<?php

namespace Acme\Media\Contracts;

interface StoresImages
{
    public function store(string $path): string;
}
