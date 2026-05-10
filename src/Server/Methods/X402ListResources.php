<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Laravel\Mcp\Server\Methods\ListResources;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use X402\Laravel\Mcp\Server\Concerns\AdvertisesX402Price;

/**
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\ListResources`
 * that advertises `#[X402Price]` as `_meta["x402/price"]` on each
 * priced resource. Templates are filtered by the parent
 * `ServerContext::resources()` and listed via
 * `resources/templates/list` — pricing on a template still gates
 * every concrete URI it serves. Free resources pass through unchanged.
 */
final class X402ListResources extends ListResources
{
    use AdvertisesX402Price;

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return $this->advertisePrices($request, $context->resources(), $context, 'resources');
    }
}
