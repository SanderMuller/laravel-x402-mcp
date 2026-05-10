<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Attributes;

use Attribute;
use Laravel\Mcp\Server\Primitive;
use ReflectionClass;

/**
 * Declare a price for a Laravel MCP primitive — `Tool`, `Resource`, or
 * `Prompt`. The attribute targets a class; the gating handlers
 * (`X402CallTool` / `X402ReadResource` / `X402GetPrompt`) reflect for it
 * at request time.
 *
 * Usage:
 *
 *   #[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
 *   final class FetchPremiumData extends \Laravel\Mcp\Server\Tool { ... }
 *
 * Resolved at request time via PHP reflection — same pattern as
 * laravel/mcp's own `RendersApp` attribute, so the primitive's
 * `_meta["x402/price"]` block can be attached without subclassing.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class X402Price
{
    /**
     * Wire-format `_meta` key for advertised prices on
     * `tools/list` / `resources/list` / `prompts/list`.
     */
    public const META_KEY = 'x402/price';

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
     * Pull the `#[X402Price]` instance off any MCP primitive (Tool,
     * Resource, or Prompt — anything extending `Laravel\Mcp\Server\Primitive`),
     * or `null` when the primitive is not gated. Centralizes the
     * reflection dance shared by `X402CallTool`, `X402ListTools`,
     * `X402ReadResource`, `X402GetPrompt`, and `ListToolsCommand`.
     */
    public static function resolveFor(Primitive $primitive): ?self
    {
        $attributes = (new ReflectionClass($primitive))->getAttributes(self::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Wire-format meta block for `_meta["x402/price"]` on
     * `tools/list` / `resources/list` / `prompts/list`. Omits `payTo`
     * when it falls back to the default recipient — agents only need
     * to see the override when one is set.
     *
     * @return array{amount: string, asset: string, network: string, payTo?: string}
     */
    public function toMetaArray(): array
    {
        $meta = [
            'amount' => $this->amount,
            'asset' => $this->asset,
            'network' => $this->network,
        ];

        if ($this->payTo !== null) {
            $meta['payTo'] = $this->payTo;
        }

        return $meta;
    }
}
