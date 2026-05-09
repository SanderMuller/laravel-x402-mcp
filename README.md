# laravel-x402-mcp

Gate [`laravel/mcp`](https://github.com/laravel/mcp) tools behind x402 stablecoin payments. Conformant with the x402 v2 MCP transport spec (`specs/transports-v2/mcp.md`).

Bridge between [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402) (^0.2) and `laravel/mcp` (^0.6 || ^0.7). Annotate paid tools with the `#[X402Price]` attribute. Agents include the signed payment payload in `params._meta["x402/payment"]` (JSON-RPC level — not an HTTP header). The advertised price travels back on `tools/list` via `_meta["x402/price"]`.

## Install

```bash
composer require sandermuller/laravel-x402-mcp
```

The bridge inherits its facilitator wiring, recipient address, and asset config from `laravel-x402`. Run that package's installer first:

```bash
php artisan x402:install
```

This sets `X402_RECIPIENT` and (optionally) `X402_PRIVATE_KEY` in `.env` and publishes the `config/x402.php` file. Verify with `php artisan x402:verify-config`.

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

`payTo` overrides the global `x402.recipient` for a specific tool:

```php
#[X402Price(amount: '5.00', asset: 'USDC', network: 'base', payTo: '0xa11ce...')]
```

### 2. Wire the gating method handlers on your Server

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

The `WithX402Payment` trait registers two method handlers when the server starts:

- `tools/list` → `X402ListTools` — advertises priced tools as `_meta["x402/price"]` so agents know the price *before* invoking.
- `tools/call` → `X402CallTool` — gates priced tools behind a verified + settled payment; passes free tools through unchanged.

The trait hooks on `start()`, not `boot()`, so any subclass overriding `boot()` still gets x402 gating without having to know about this trait. If you want to opt *out* of trait defaults — for example to register your own `tools/call` handler — use `addMethod()` inside `boot()`; explicit registrations made there win over the trait. If you also override `start()`, call `parent::start()` so the trait runs.

### 3. What `X402CallTool` does

1. Looks up the invoked tool, checks for `#[X402Price]`.
2. If unpriced — passes through to the standard `CallTool`.
3. If priced — reads `params._meta["x402/payment"]` for the signed payload, verifies + settles via the bound `FacilitatorClient`, then runs the tool.
4. On success, injects `result._meta["x402/payment-response"]` with the settlement receipt.
5. On any failure, returns a tool result with `isError: true` + `structuredContent: PaymentRequired` + `content[0].text` (JSON-stringified).

The replay store from `laravel-x402` is reused — concurrent requests with the same authorization are rejected before hitting the facilitator.

## Wire format

Per [`specs/transports-v2/mcp.md`](https://github.com/coinbase/x402/blob/main/specs/transports-v2/mcp.md):

| Direction | Location | Shape |
|---|---|---|
| Client → Server (payment) | `params._meta["x402/payment"]` | `PaymentPayload` v2 envelope |
| Server → Client (settled) | `result._meta["x402/payment-response"]` | `{success, transaction, network, payer}` |
| Server → Client (required) | `result.structuredContent` + `result.content[0].text` + `result.isError = true` | `PaymentRequired` |
| Server → Client (advertised) | `tools[i]._meta["x402/price"]` on `tools/list` | `{amount, asset, network[, payTo]}` |

The HTTP-level `PAYMENT-SIGNATURE` / `PAYMENT-RESPONSE` headers used by the x402 HTTP transport are **NOT** used in MCP — payment travels at the JSON-RPC layer, inside the request/response body.

## Stdio transport

Stdio MCP servers can also receive `_meta["x402/payment"]` because `_meta` is a JSON-RPC field, not an HTTP envelope. Paid tools work on stdio as well as HTTP.

## Operator commands

```bash
php artisan x402-mcp:list-tools "App\\Mcp\\Servers\\MyMcpServer"
```

Lists every tool on the given Server class, marking gated tools with their amount, asset, network, and `payTo` (or `(default)` when not overridden). Free tools render as `(free)`. Mirrors `x402:list-routes` from `laravel-x402` for the JSON-RPC transport.

## Testing

`laravel-x402` ships a recording fake. Swap it in once at the top of a test and the bridge picks it up automatically:

```php
use X402\Laravel\Facades\X402;

$fake = X402::fake();

// Drive the MCP server however you normally would (HTTP, stdio, in-process).
$this->postJson('/mcp', $jsonRpcCall)->assertOk();

$fake->assertVerified('mcp://tool/fetch-premium-data');
$fake->assertSettled('mcp://tool/fetch-premium-data');
```

`PaymentSettled` / `PaymentRejected` events still fire through `DispatchingFacilitator`, so `Event::fake([PaymentSettled::class])` composes alongside.

## License

MIT.
