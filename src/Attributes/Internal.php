<?php

declare(strict_types=1);

namespace Cordon\Attributes;

use Attribute;

/**
 * Marks a class as internal to its module, even if it lives in a public namespace.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Internal {}
