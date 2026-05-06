# laravel-x402-mcp

Gate [`laravel/mcp`](https://github.com/laravel/mcp) tools behind x402 stablecoin payments. Conformant with the x402 v2 MCP transport spec (`specs/transports-v2/mcp.md`).

> **Status:** scaffolding. Not yet usable.

Bridge between [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402) and `laravel/mcp` (^0.6 || ^0.7). Annotate paid tools with the `#[X402Price]` attribute. Agents include the signed payment payload in `params._meta["x402/payment"]` (JSON-RPC level — not an HTTP header).

## Install

```bash
composer require sandermuller/laravel-x402-mcp
```

## Usage

### 1. Annotate a paid tool

```php
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use X402\Laravel\Mcp\Attributes\X402Price;

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class FetchPremiumData extends Tool
{
    public function description(): string
    {
        return 'Premium dataset. Costs $0.01 USDC on Base.';
    }

    public function handle(Request $request): Response
    {
        // Runs only after payment is settled.
        return Response::json(['data' => '...']);
    }
}
```

### 2. Wire the gating method handler on your Server

```php
use Laravel\Mcp\Server\Server;
use X402\Laravel\Mcp\Server\Concerns\WithX402Payment;

final class MyMcpServer extends Server
{
    use WithX402Payment;

    protected array $tools = [
        FetchPremiumData::class,
    ];
}
```

The `WithX402Payment` trait swaps `tools/call` for `X402CallTool`, which:

1. Looks up the invoked tool, checks for `#[X402Price]`.
2. If unpriced — passes through to the standard `CallTool`.
3. If priced — reads `params._meta["x402/payment"]` for the signed payload, verifies + settles via the configured facilitator, then runs the tool.
4. On success, injects `result._meta["x402/payment-response"]` with the settlement receipt.
5. On any failure, returns a tool result with `isError: true` + `structuredContent: PaymentRequired` + `content[0].text` (JSON-stringified).

## Wire format

Per [`specs/transports-v2/mcp.md`](https://github.com/coinbase/x402/blob/main/specs/transports-v2/mcp.md):

| Direction | Location | Shape |
|---|---|---|
| Client → Server (payment) | `params._meta["x402/payment"]` | `PaymentPayload` v2 envelope |
| Server → Client (settled) | `result._meta["x402/payment-response"]` | `{success, transaction, network, payer}` |
| Server → Client (required) | `result.structuredContent` + `result.content[0].text` + `result.isError = true` | `PaymentRequired` |

The HTTP-level `PAYMENT-SIGNATURE` / `PAYMENT-RESPONSE` headers used by the x402 HTTP transport are **NOT** used in MCP — payment travels at the JSON-RPC layer, inside the request/response body.

## Stdio transport

Stdio MCP servers can also receive `_meta["x402/payment"]` because `_meta` is a JSON-RPC field, not an HTTP envelope. Paid tools work on stdio as well as HTTP.

## License

MIT.
