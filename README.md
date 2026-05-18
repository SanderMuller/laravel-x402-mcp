# laravel-x402-mcp

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/laravel-x402-mcp.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-x402-mcp)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sandermuller/laravel-x402-mcp/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sandermuller/laravel-x402-mcp/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/laravel-x402-mcp.svg?style=flat-square)](https://packagist.org/packages/sandermuller/laravel-x402-mcp)
[![License](https://img.shields.io/packagist/l/sandermuller/laravel-x402-mcp.svg?style=flat-square)](LICENSE)

Gate [`laravel/mcp`](https://github.com/laravel/mcp) tools behind x402 stablecoin payments. Conformant with the x402 v2 MCP transport spec (`specs/transports-v2/mcp.md`).

Bridge between [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402) (^0.5) and `laravel/mcp` (^0.6 || ^0.7). Annotate paid tools with the `#[X402Price]` attribute. Agents include the signed payment payload in `params._meta["x402/payment"]` (JSON-RPC level — not an HTTP header). The advertised price travels back on `tools/list` / `resources/list` / `prompts/list` via `_meta["x402/price"]`.

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

The `WithX402Payment` trait registers six method handlers when the server starts:

- `tools/list` → `X402ListTools` — advertises priced tools as `_meta["x402/price"]` so agents know the price *before* invoking.
- `tools/call` → `X402CallTool` — gates priced tools behind a verified + settled payment; passes free tools through unchanged.
- `resources/list` → `X402ListResources` — advertises priced resources as `_meta["x402/price"]` (templates excluded; they list under `resources/templates/list`).
- `resources/read` → `X402ReadResource` — gates priced `Resource` subclasses. The resource's URI is the challenge resource verbatim (no synthetic prefix).
- `prompts/list` → `X402ListPrompts` — advertises priced prompts as `_meta["x402/price"]`.
- `prompts/get` → `X402GetPrompt` — gates priced `Prompt` subclasses. Synthesises `mcp://prompt/{name}` for the challenge resource.

The trait hooks on `start()`, not `boot()`, so any subclass overriding `boot()` still gets x402 gating without having to know about this trait. If you want to opt *out* of trait defaults — for example to register your own `tools/call` handler — use `addMethod()` inside `boot()`; explicit registrations made there win over the trait. If you also override `start()`, call `parent::start()` so the trait runs.

### Gating resources and prompts

The same `#[X402Price]` attribute applies to `Resource` and `Prompt` subclasses:

```php
use Laravel\Mcp\Server\Resource;
use X402\Laravel\Mcp\Attributes\X402Price;

#[X402Price(amount: '0.05', asset: 'USDC', network: 'base')]
final class PremiumDataset extends Resource
{
    protected string $uri = 'data://premium/v1';

    public function handle(): Response
    {
        return Response::text('...');
    }
}
```

Differences from tool gating:

- **Challenge URI shape.** Tools use `mcp://tool/{name}`. Resources use the request URI verbatim (e.g. `data://premium/v1`) — they're already URI-addressed. Prompts use `mcp://prompt/{name}`.
- **`HasUriTemplate` resources.** A priced template gates *every* concrete URI under it — `parent::invokeResource` is used (not a bare container call), so URI-template variables stay bound to the request inside the resource handler.
- **Error envelope shape.** A 402 challenge for a paid resource or prompt uses the same `result.isError + structuredContent: PaymentRequired + content[0].text` envelope as `tools/call`. `X402ReadResource` and `X402GetPrompt` both implement `Errable` so the envelope serialises as a JSON-RPC `result`, not a JSON-RPC error.

### 3. What `X402CallTool` does

1. Looks up the invoked tool, checks for `#[X402Price]`.
2. If unpriced — passes through to the standard `CallTool`.
3. If priced — reads `params._meta["x402/payment"]` for the signed payload, verifies + settles via the bound `FacilitatorClient`, then runs the tool.
4. On success, injects `result._meta["x402/payment-response"]` with the settlement receipt.
5. On any failure, returns a tool result with `isError: true` + `structuredContent: PaymentRequired` + `content[0].text` (JSON-stringified).

The replay store from `laravel-x402` is reused — concurrent requests with the same authorization are rejected before hitting the facilitator.

### Post-settle tool failure

Settlement happens *before* the tool runs. If the tool throws after the facilitator has settled, the payment has already moved on-chain and is not refundable from this layer. **The settlement receipt always lands on the response** — every `Throwable` thrown by the tool (synchronous or mid-stream in a generator) is caught, returned as a tool error result, and stamped with `result._meta["x402/payment-response"]` so agents can prove the payment settled even when delivery failed. Two exceptions to that guarantee:

- `JsonRpcException` — a tool that throws this is signalling an explicit transport-level protocol error; it surfaces as a JSON-RPC error envelope, not a tool result, and carries no receipt.
- The agent disconnects mid-stream — the receipt was generated but never reached the wire. The on-chain settlement stands; a future idempotency cache will let retries replay the cached response.

This ordering is by design: the x402 settle is the canonical proof of payment, and the spec requires it to be observable independently of tool execution. If your tool needs transactional "execute-or-refund" semantics, do the work in two steps — settle the user into a credit balance first, debit on successful execution — rather than relying on this layer.

## Wire format

Per [`specs/transports-v2/mcp.md`](https://github.com/coinbase/x402/blob/main/specs/transports-v2/mcp.md):

| Direction | Location | Shape |
|---|---|---|
| Client → Server (payment) | `params._meta["x402/payment"]` | `PaymentPayload` v2 envelope |
| Server → Client (settled) | `result._meta["x402/payment-response"]` | `{success, transaction, network, payer}` |
| Server → Client (required) | `result.structuredContent` + `result.content[0].text` + `result.isError = true` | `PaymentRequired` |
| Server → Client (advertised) | `_meta["x402/price"]` on each item of `tools/list` / `resources/list` / `prompts/list` | `{amount, asset, network[, payTo]}` |

The same `_meta["x402/payment"]` / `_meta["x402/payment-response"]` envelope and the same 402 challenge shape apply to `resources/read` and `prompts/get` — the gating mirrors `tools/call` 1:1, only the challenge resource URI differs (resources use the request URI verbatim; prompts use `mcp://prompt/{name}`).

The HTTP-level `PAYMENT-SIGNATURE` / `PAYMENT-RESPONSE` headers used by the x402 HTTP transport are **NOT** used in MCP — payment travels at the JSON-RPC layer, inside the request/response body.

## Idempotency

A transport drop between facilitator-settle and JSON-RPC delivery would otherwise leave the user paid without recourse: the agent retries the same signed authorization, the replay-guard rejects the duplicate nonce with `replay_attempt`, and there's no path back. The bundled `PaidToolResponseCache` closes that gap — the same idea as `laravel-x402` 0.3's `x402.cache` middleware, applied to JSON-RPC `tools/call`, `resources/read`, and `prompts/get`.

How it works:

1. Before claiming the nonce, the handler computes a `CacheScope` (`tools/call|mcp://tool/{name}|sha256(canonical_args)` etc.) and looks up a cached response keyed by `(scope, signature)`.
2. On HIT: rebuild the cached `JsonRpcResponse` with the new request's `id` and return it. No facilitator round-trip, no nonce burn.
3. On MISS: claim the nonce, settle, run the primitive, store the result under `(scope, signature)`, return.

What's pinned in the key:

- **Scope segments** — method (`tools/call` / `resources/read` / `prompts/get`), challenge resource URI (`mcp://tool/{name}` / concrete resource URI / `mcp://prompt/{name}`), and a `sha256` of the canonical-JSON `params.arguments` for tools and prompts. Two reads of `mcp://x/users/1` and `mcp://x/users/2` under the same priced template never collide; same payload + different tool name falls through to `guardReplay` and gets `replay_attempt`.
- **Forge-resistance binding** — the EIP-3009 `signature` field. An attacker who observed the public `(network, from, nonce)` tuple post-settlement cannot produce a different request that hashes to the same key without the private key.

What's NOT cached:

- **402 challenges** — every retry must reprompt for payment.
- **Streaming responses** (`Generator` returns) — there's no atomic snapshot to replay.
- **Tool / resource / prompt errors** (`isError: true`) — a primitive that errored on a settled payment may have transient state; retries deserve a fresh handler call.
- **Sub-second concurrent retries** — the lookup-then-claim ordering does not eliminate retry storms. Two retries within milliseconds can both miss the lookup; one wins `guardReplay`, the other gets `replay_attempt`. Real MCP transports rarely fire sub-second retries (HTTP/2 retry policies and stdio reuse a single in-flight call), so the gap is mostly theoretical. Revisit with a pending-reservation pattern if a production deployment surfaces a real complaint.

Configuration. The cache-store name and TTL are shared with `laravel-x402`'s HTTP middleware (one knob across both transports); the cache prefix is MCP-namespaced so HTTP and JSON-RPC consumers can co-exist on a shared Redis without colliding.

| Key | Default | Effect |
|---|---|---|
| `x402.response_cache.cache_store` | `null` (Laravel default store) | PSR-16-bridged store name |
| `x402.response_cache.ttl` | `3600` | Idempotency window in seconds |
| `x402_mcp.response_cache.prefix` | `x402:idem:mcp:` | JSON-RPC cache-key prefix |

The store binding is via `laravel-x402`'s `LaravelPsr16Bridge` over Illuminate's cache repository — bind any PSR-16 backend (Redis, file, array). The TTL must comfortably exceed the nonce-store's TTL so a retry that arrives after the nonce expires still hits the response cache. Use a persistent store (Redis) in production; the array driver is process-local and only useful in tests.

## Stdio transport

Stdio MCP servers can also receive `_meta["x402/payment"]` because `_meta` is a JSON-RPC field, not an HTTP envelope. Paid tools work on stdio as well as HTTP.

## Operator commands

```bash
php artisan x402-mcp:list-tools "App\\Mcp\\Servers\\MyMcpServer"
```

Lists every tool, resource, and prompt on the given Server class with a `Type` column, marking gated entries with their amount, asset, network, and `payTo` (or `(default)` when not overridden). Free entries render as `(free)`. Mirrors `x402:list-routes` from `laravel-x402` for the JSON-RPC transport.

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
