<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Contracts\Errable;
use X402\Laravel\Mcp\Server\Concerns\WithX402Payment;
use X402\Laravel\Mcp\Server\Methods\X402GetPrompt;
use X402\Laravel\Mcp\Server\Methods\X402ReadResource;
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

// Without the `Errable` marker, vendor `Concerns/InteractsWithResponses::toJsonRpcResponse`
// throws `JsonRpcException` when these handlers serialize a `Response::error(...)` body.
// That would convert every 402 challenge into a JSON-RPC protocol error instead of the
// spec-mandated tool-result-with-isError envelope. Lock the marker so a future refactor
// can't silently drop it.
arch('X402ReadResource implements Errable so 402 challenges serialise as result.isError')
    ->expect(X402ReadResource::class)
    ->toImplement(Errable::class);

arch('X402GetPrompt implements Errable so 402 challenges serialise as result.isError')
    ->expect(X402GetPrompt::class)
    ->toImplement(Errable::class);
