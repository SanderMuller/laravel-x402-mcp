<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Concerns;

use Laravel\Mcp\Server\Methods\CompletionComplete;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Methods\Initialize;
use Laravel\Mcp\Server\Methods\ListPrompts;
use Laravel\Mcp\Server\Methods\ListResources;
use Laravel\Mcp\Server\Methods\ListResourceTemplates;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Methods\Ping;
use Laravel\Mcp\Server\Methods\ReadResource;
use X402\Laravel\Mcp\Server\Methods\X402CallTool;

/**
 * Drop-in trait for `Laravel\Mcp\Server\Server` subclasses.
 *
 * Replaces the default `tools/call` method handler with `X402CallTool`,
 * which gates any tool annotated with `#[X402Price]` behind an x402
 * payment.
 *
 * Usage:
 *
 *   final class MyMcpServer extends \Laravel\Mcp\Server\Server
 *   {
 *       use WithX402Payment;
 *   }
 *
 * Tools without `#[X402Price]` pass through untouched.
 *
 * **Conflict with custom `$methods` overrides:** PHP property resolution
 * picks the class declaration over a trait declaration. A subclass that
 * already declares `protected array $methods = [...]` will silently
 * override this trait. If you need to mix the two, copy the entries
 * from this trait into your own `$methods` array — PHP has no
 * inheritance merge for class-level array properties.
 *
 * **Upstream drift:** the trait pins the full method map at the
 * laravel/mcp v0.7 baseline because PHP property override is a full
 * replace, not a merge. If laravel/mcp ships a new JSON-RPC method
 * (e.g. `tools/list-changed` notifications), this trait must be
 * resynced to include the new default handler — otherwise users of
 * `WithX402Payment` won't see the new method on their server.
 * Track upstream changes in `vendor/laravel/mcp/src/Server.php`.
 */
trait WithX402Payment
{
    /**
     * @var array<string, class-string>
     */
    protected array $methods = [
        'initialize' => Initialize::class,
        'ping' => Ping::class,
        'tools/list' => ListTools::class,
        'tools/call' => X402CallTool::class,
        'prompts/list' => ListPrompts::class,
        'prompts/get' => GetPrompt::class,
        'resources/list' => ListResources::class,
        'resources/templates/list' => ListResourceTemplates::class,
        'resources/read' => ReadResource::class,
        'completion/complete' => CompletionComplete::class,
    ];
}
