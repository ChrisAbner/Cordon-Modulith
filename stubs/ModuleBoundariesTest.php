<?php

use Cordon\Testing\Cordon;

// Fails with every module that crosses its boundaries, like cordon:verify.
it('keeps every module within its boundaries', fn () => Cordon::assertBoundaries());
