<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Tests\TestCase;

require_once __DIR__ . '/Support/X402TestHelpers.php';

pest()
    ->extend(TestCase::class)
    ->in('Feature');
uses()->in('Arch');

// Tia re-runs only the tests a change touches. Local only — never wire
// `--tia` into a composer script or a CI step.
pest()->tia()->locally();
