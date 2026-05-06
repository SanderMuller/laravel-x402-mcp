<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Workbench\App\Mcp\SampleServer;

/**
 * Workbench-only provider — never ships in the package.
 *
 * Mounts a sample MCP server at `/mcp`. `vendor/bin/testbench serve`
 * will expose it for manual testing.
 *
 * No HTTP middleware is needed — the x402 receipt is attached at the
 * JSON-RPC level via X402CallTool injecting `result._meta["x402/payment-response"]`.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Mcp::class)) {
            return;
        }

        Mcp::web('/mcp', SampleServer::class);
    }
}
