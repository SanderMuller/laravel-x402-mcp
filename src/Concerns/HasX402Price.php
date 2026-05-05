<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Concerns;

use ReflectionClass;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Support\PriceMeta;

/**
 * Mixin for Laravel MCP Tool classes — injects the `_meta.x402` block into
 * tools/list output. Reads the `#[X402Price]` attribute via reflection.
 *
 * Use alongside the attribute:
 *
 *   #[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
 *   final class FetchPremiumData extends Tool {
 *       use HasX402Price;
 *   }
 *
 * The trait overrides `toArray()` (which laravel/mcp's base Tool already
 * provides). It calls the parent and merges the meta block.
 *
 * @internal Bridge implementation; consumers shouldn't reach into it.
 */
trait HasX402Price
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $base */
        $base = parent::toArray();

        $price = (new ReflectionClass($this))->getAttributes(X402Price::class);

        if ($price === []) {
            return $base;
        }

        /** @var X402Price $instance */
        $instance = $price[0]->newInstance();

        $base['_meta'] = array_merge(
            (array) ($base['_meta'] ?? []),
            ['x402' => PriceMeta::build($instance)],
        );

        return $base;
    }
}
