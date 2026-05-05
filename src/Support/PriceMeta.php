<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Support;

use X402\Laravel\Mcp\Attributes\X402Price;

/**
 * Build the `_meta.x402` payload that x402-aware MCP clients (e.g. Vercel's
 * x402-mcp TS SDK) read off `tools/list` to know what each paid tool costs.
 *
 * Format mirrors the Vercel convention:
 *
 *   {
 *       "amount": "0.01",
 *       "asset": "USDC",
 *       "network": "base",
 *       "payTo": "0x..."
 *   }
 */
final class PriceMeta
{
    /**
     * @return array<string, string>
     */
    public static function build(X402Price $price): array
    {
        $payTo = $price->payTo ?? (string) config('x402.recipient', '');

        return [
            'amount' => $price->amount,
            'asset' => $price->asset,
            'network' => $price->network,
            'payTo' => $payTo,
        ];
    }
}
