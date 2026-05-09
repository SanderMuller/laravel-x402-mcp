<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Pagination\CursorPaginator;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use X402\Laravel\Mcp\Attributes\X402Price;

/**
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\ListTools` that
 * advertises `#[X402Price]` as `_meta["x402/price"]` on each priced tool
 * in the listing, so agents can discover prices before invoking a tool
 * and avoid a wasted 402 round-trip.
 *
 * Shape (per priced tool):
 *
 *   _meta:
 *     x402/price:
 *       amount: '0.01'   # human-decimal value from the attribute
 *       asset: 'USDC'    # symbol from the attribute
 *       network: 'base'  # network slug from the attribute (raw CAIP-2 also OK)
 *       payTo: '0x...'   # only when overridden on the attribute
 *
 * Free tools (no `#[X402Price]`) pass through unchanged.
 */
final class X402ListTools extends ListTools
{
    private const META_KEY = 'x402/price';

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $tools = $context->tools()->each($this->annotatePrice(...));

        $perPageRaw = $request->get('per_page');
        $perPage = is_int($perPageRaw) ? $perPageRaw : null;

        $paginator = new CursorPaginator(
            items: $tools,
            perPage: $context->perPage($perPage),
            cursor: $request->cursor(),
        );

        return JsonRpcResponse::result($request->id, $paginator->paginate('tools'));
    }

    private function annotatePrice(Tool $tool): void
    {
        $price = X402Price::resolveFor($tool);

        if (! $price instanceof X402Price) {
            return;
        }

        $meta = [
            'amount' => $price->amount,
            'asset' => $price->asset,
            'network' => $price->network,
        ];

        if ($price->payTo !== null) {
            $meta['payTo'] = $price->payTo;
        }

        $tool->setMeta(self::META_KEY, $meta);
    }
}
