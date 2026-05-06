<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp;

use Illuminate\Support\ServiceProvider;

/**
 * The bridge needs no boot-time wiring of its own — `X402CallTool` is
 * resolved through the Laravel container when the host's Server class
 * uses `WithX402Payment` (or wires the method handler manually). Keep
 * this provider as a marker for service discovery and future additions.
 */
final class X402McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reserved for future bindings.
    }

    public function boot(): void
    {
        // Reserved for future boot logic (e.g. opt-in config publishing).
    }
}
