<?php

declare(strict_types=1);

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use X402\Laravel\Mcp\Server\Concerns\WithX402Payment;
use X402\Laravel\Mcp\Server\Methods\X402CallTool;
use X402\Laravel\Mcp\Server\Methods\X402GetPrompt;
use X402\Laravel\Mcp\Server\Methods\X402ListPrompts;
use X402\Laravel\Mcp\Server\Methods\X402ListResources;
use X402\Laravel\Mcp\Server\Methods\X402ListTools;
use X402\Laravel\Mcp\Server\Methods\X402ReadResource;

/**
 * @return array<string, class-string>
 */
function readServerMethods(Server $server): array
{
    $reflection = new ReflectionProperty(Server::class, 'methods');

    /** @var array<string, class-string> $methods */
    $methods = $reflection->getValue($server);

    return $methods;
}

final class TraitFixtureServer extends Server
{
    use WithX402Payment;
}

it('replaces the six paid-flow handlers when start() runs and leaves the rest of the laravel/mcp method map intact', function (): void {
    $server = new TraitFixtureServer(new FakeTransporter());

    $server->start();

    $methods = readServerMethods($server);

    expect($methods['tools/call'] ?? null)->toBe(X402CallTool::class)
        ->and($methods['tools/list'] ?? null)->toBe(X402ListTools::class)
        ->and($methods['resources/list'] ?? null)->toBe(X402ListResources::class)
        ->and($methods['resources/read'] ?? null)->toBe(X402ReadResource::class)
        ->and($methods['prompts/list'] ?? null)->toBe(X402ListPrompts::class)
        ->and($methods['prompts/get'] ?? null)->toBe(X402GetPrompt::class)
        ->and($methods['ping'] ?? null)->toBe(Ping::class);
});

it('survives an upstream-added method handler registered inside a custom boot()', function (): void {
    $server = new class (new FakeTransporter()) extends Server {
        use WithX402Payment;

        protected function boot(): void
        {
            parent::boot();

            // Simulate a future laravel/mcp method handler the user wires manually.
            $this->addMethod('tools/list-changed', Ping::class);
        }
    };

    $server->start();

    $methods = readServerMethods($server);

    expect($methods['tools/list-changed'] ?? null)->toBe(Ping::class)
        ->and($methods['tools/call'] ?? null)->toBe(X402CallTool::class);
});

it('keeps x402 gating active when a subclass overrides boot() without calling bootX402Payment', function (): void {
    $server = new class (new FakeTransporter()) extends Server {
        use WithX402Payment;

        // No call to parent's trait method, no call to bootX402Payment().
        // The trait must still register x402 handlers via start().
        protected function boot(): void
        {
            // Intentionally empty — simulates downstream code that overrode
            // boot() before this trait shipped.
        }
    };

    $server->start();

    $methods = readServerMethods($server);

    expect($methods['tools/call'] ?? null)->toBe(X402CallTool::class)
        ->and($methods['tools/list'] ?? null)->toBe(X402ListTools::class)
        ->and($methods['resources/list'] ?? null)->toBe(X402ListResources::class)
        ->and($methods['resources/read'] ?? null)->toBe(X402ReadResource::class)
        ->and($methods['prompts/list'] ?? null)->toBe(X402ListPrompts::class)
        ->and($methods['prompts/get'] ?? null)->toBe(X402GetPrompt::class);
});

it('lets a user explicit addMethod inside boot() override the trait default', function (): void {
    $server = new class (new FakeTransporter()) extends Server {
        use WithX402Payment;

        // User explicitly opts out of x402 gating for tools/call by registering
        // their own handler. Their choice must win over the trait default.
        protected function boot(): void
        {
            $this->addMethod('tools/call', CallTool::class);
        }
    };

    $server->start();

    expect(readServerMethods($server)['tools/call'] ?? null)->toBe(CallTool::class);
});

it('falls back to the default CallTool when WithX402Payment is not used', function (): void {
    $server = new class (new FakeTransporter()) extends Server {};

    $server->start();

    expect(readServerMethods($server)['tools/call'] ?? null)->toBe(CallTool::class);
});

it('still registers x402 handlers via handle() when a subclass shadows start()', function (): void {
    $server = new class (new FakeTransporter()) extends Server {
        use WithX402Payment;

        // Subclass overrides start() WITHOUT calling parent::start. PHP picks
        // this declaration over the trait method, so the trait's start() never
        // runs. The handle() safety net must still register x402 handlers.
        public function start(): void
        {
            // Intentionally empty.
        }
    };

    // A trivial JSON-RPC ping is enough to drive handle() through the trait.
    $server->handle('{"jsonrpc":"2.0","id":1,"method":"ping"}');

    $methods = readServerMethods($server);

    expect($methods['tools/call'] ?? null)->toBe(X402CallTool::class)
        ->and($methods['tools/list'] ?? null)->toBe(X402ListTools::class)
        ->and($methods['resources/list'] ?? null)->toBe(X402ListResources::class)
        ->and($methods['resources/read'] ?? null)->toBe(X402ReadResource::class)
        ->and($methods['prompts/list'] ?? null)->toBe(X402ListPrompts::class)
        ->and($methods['prompts/get'] ?? null)->toBe(X402GetPrompt::class);
});
