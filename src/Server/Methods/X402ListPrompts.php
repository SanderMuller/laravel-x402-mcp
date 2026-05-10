<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Laravel\Mcp\Server\Methods\ListPrompts;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use X402\Laravel\Mcp\Server\Concerns\AdvertisesX402Price;

/**
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\ListPrompts` that
 * advertises `#[X402Price]` as `_meta["x402/price"]` on each priced
 * prompt. Free prompts pass through unchanged.
 */
final class X402ListPrompts extends ListPrompts
{
    use AdvertisesX402Price;

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return $this->advertisePrices($request, $context->prompts(), $context, 'prompts');
    }
}
