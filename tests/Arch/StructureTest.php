<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Server\Concerns\WithX402Payment;
use X402\Laravel\Mcp\X402McpServiceProvider;

arch('every src class declares strict types')
    ->expect('X402\Laravel\Mcp')
    ->toUseStrictTypes();

arch('no debug helpers ship in production')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'xdebug_break'])
    ->not->toBeUsed();

arch('attributes are readonly')
    ->expect('X402\Laravel\Mcp\Attributes')
    ->classes()
    ->toBeReadonly();

arch('concrete classes are final')
    ->expect('X402\Laravel\Mcp')
    ->classes()
    ->toBeFinal()
    ->ignoring([
        X402McpServiceProvider::class, // must be extendable for host overrides
        WithX402Payment::class, // trait
    ]);
