<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use X402\Laravel\Mcp\Server\Concerns\AdvertisesX402Price;

/**
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\ListTools` that
 * advertises `#[X402Price]` as `_meta["x402/price"]` on each priced
 * tool, so agents can discover prices before calling and avoid a
 * wasted 402 round-trip. Free tools pass through unchanged.
 */
final class X402ListTools extends ListTools
{
    use AdvertisesX402Price;

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return $this->advertisePrices($request, $context->tools(), $context, 'tools');
    }
}
