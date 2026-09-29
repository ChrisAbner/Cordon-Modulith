<?php

declare(strict_types=1);

namespace Cordon\Contracts;

use Cordon\Module\ModuleMap;

/**
 * Discovers the modules of an application (nwidart, InterNACHI, plain namespaces...).
 */
interface ModuleResolver
{
    public function resolve(): ModuleMap;
}
