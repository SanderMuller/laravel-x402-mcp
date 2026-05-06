<?php

declare(strict_types=1);

namespace Workbench\App\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use X402\Laravel\Mcp\Attributes\X402Price;

/**
 * Workbench-only sample paid tool. Use to manually exercise the bridge
 * with `vendor/bin/testbench mcp` or curl against the registered MCP route.
 */
#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class FetchPremiumDataTool extends Tool
{
    public function description(): string
    {
        return 'Returns a sample premium dataset. Costs $0.01 in USDC on Base.';
    }

    public function handle(Request $request): Response
    {
        return Response::json([
            'data' => 'You paid for this!',
            'received_at' => date(DATE_ATOM),
        ]);
    }
}
