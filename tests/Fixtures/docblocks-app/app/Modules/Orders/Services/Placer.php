<?php

namespace App\Modules\Orders\Services;

/**
 * Orders uses Billing only in a docblock: with docblocks on, the two modules close a cycle.
 */
final class Placer
{
    /** @var \App\Modules\Billing\Contracts\Invoicing|null */
    private $invoicing = null;
}
