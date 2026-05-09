<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Attributes;

use Attribute;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * Declare a price for a Laravel MCP tool.
 *
 * Usage:
 *
 *   #[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
 *   final class FetchPremiumData extends \Laravel\Mcp\Server\Tool { ... }
 *
 * Resolved at boot via PHP reflection — same pattern as laravel/mcp's own
 * `RendersApp` attribute, so the tool's `_meta.x402` block can be attached
 * without subclassing.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class X402Price
{
    /**
     * @param  string  $amount  Decimal in human units, e.g. "0.01" for 1¢ USDC.
     * @param  string  $asset  Symbol for documentation; the actual contract address comes from x402 config.
     * @param  string  $network  Network slug (base, base-sepolia, ethereum, polygon, arbitrum) or raw CAIP-2.
     * @param  string|null  $payTo  Optional recipient override (defaults to x402.recipient config).
     */
    public function __construct(
        public string $amount,
        public string $asset = 'USDC',
        public string $network = 'base',
        public ?string $payTo = null,
    ) {}

    /**
     * Pull the `#[X402Price]` instance off a Tool, or `null` when the tool
     * is not gated. Centralizes the reflection dance shared by `X402CallTool`,
     * `X402ListTools`, and `ListToolsCommand`.
     */
    public static function resolveFor(Tool $tool): ?self
    {
        $attributes = (new ReflectionClass($tool))->getAttributes(self::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }
}
