# laravel-x402-mcp

Gate [`laravel/mcp`](https://github.com/laravel/mcp) tools behind x402 stablecoin payments.

> **Status:** scaffolding. Not yet usable.

Bridge between [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402) and `laravel/mcp`. Annotate paid tools with the `#[X402Price]` attribute; agents see the price in `tools/list` (via `_meta.x402`) and must include a signed `PAYMENT-SIGNATURE` header on `tools/call`.

## Install

```bash
composer require sandermuller/laravel-x402-mcp
```

## Usage

```php
use Laravel\Mcp\Server\Tool;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Concerns\HasX402Price;

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class FetchPremiumData extends Tool
{
    use HasX402Price;

    public function handle(\Laravel\Mcp\Request $request): \Laravel\Mcp\Response
    {
        // Runs only after payment is settled.
        return \Laravel\Mcp\Response::json(['data' => '...']);
    }
}
```

The bridge:

1. Adds `_meta.x402` to the `tools/list` response so x402-aware agents auto-discover.
2. Intercepts `tools/call` — verifies + settles before the tool runs.
3. Attaches `PAYMENT-RESPONSE` to the outer HTTP response on success.

## Stdio transport

Stdio MCP servers don't expose HTTP headers — paid tools are skipped (treated as free) on stdio. Use Streamable HTTP transport when shipping paid tools.

## License

MIT.
