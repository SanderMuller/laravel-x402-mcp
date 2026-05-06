<?php

declare(strict_types=1);

namespace Workbench\App\Mcp;

use Laravel\Mcp\Server\Server;
use Workbench\App\Tools\FetchPremiumDataTool;
use X402\Laravel\Mcp\Server\Concerns\WithX402Payment;

/**
 * Workbench-only sample MCP server. Demonstrates wiring `WithX402Payment`
 * so `tools/call` is gated by the bridge's payment middleware.
 *
 * Mount it in routes via `Mcp::web('/mcp', SampleServer::class)`.
 */
final class SampleServer extends Server
{
    use WithX402Payment;

    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        FetchPremiumDataTool::class,
    ];
}
