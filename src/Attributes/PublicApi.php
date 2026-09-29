<?php

declare(strict_types=1);

namespace Cordon\Attributes;

use Attribute;

/**
 * Marks a class as part of its module's public API, so other modules may use it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PublicApi
{
}
