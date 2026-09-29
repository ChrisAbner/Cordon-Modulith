<?php

declare(strict_types=1);

use Cordon\Testing\BoundaryExpectation;
use Pest\Expectation;

/*
 * Registers expect('Billing')->toRespectBoundaries() when Pest is installed.
 * Loaded through Composer's "files" autoload; does nothing without Pest.
 */
if (class_exists(Expectation::class)) {
    (new Expectation(null))->extend('toRespectBoundaries', function (): Expectation {
        BoundaryExpectation::assert($this->value);

        return $this;
    });
}
