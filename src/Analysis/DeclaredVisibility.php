<?php

declare(strict_types=1);

namespace Cordon\Analysis;

/**
 * @api
 */
enum DeclaredVisibility: string
{
    case PublicApi = 'public_api';
    case Internal = 'internal';
    case Unspecified = 'unspecified';
}
