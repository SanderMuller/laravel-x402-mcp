# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.2.0 - 2026-05-10

### What's new

- **Idempotent paid-response cache (`PaidToolResponseCache`)** — JSON-RPC analogue of `laravel-x402` 0.3's `x402.cache` middleware. A retry of a settled `tools/call` / `resources/read` / `prompts/get` whose response was lost on the wire now replays the cached body instead of being rejected by the nonce-store with `replay_attempt`. Cache key `sha256(method | challenge_resource | network | from | nonce | signature)` via upstream `X402\Server\IdempotencyKeyBuilder`; scope is built per-primitive via the new `CacheScope` value object (`forToolCall($name, $arguments)` / `forResourceRead($uri)` / `forPromptGet($name, $arguments)`), with the args hash using sort-keys-recursive canonical JSON encoding so equivalent calls collapse to the same cache entry. Cross-primitive isolation is enforced — a settled `tools/call` cache entry cannot replay into a `resources/read` retry.
- **`#[X402Price]` on `Resource` and `Prompt`** — `WithX402Payment` trait now registers four method handlers (`tools/list`, `tools/call`, `resources/read`, `prompts/get`). `X402ReadResource` uses the request URI verbatim as the challenge resource (no synthetic prefix). `X402GetPrompt` synthesises `mcp://prompt/{name}`. Both implement `Errable` so the 402 challenge serialises as a JSON-RPC `result.isError` envelope instead of a JSON-RPC protocol error. `runResourceWithReceipt` delegates to `parent::invokeResource` to preserve `HasUriTemplate` variable binding and `AppResource` library-script setup.
- **`x402-mcp:list-tools` extended** — now enumerates `$tools` + `$resources` + `$prompts` with a `Type` column, mirroring `tools/list` / `resources/list` / `prompts/list` membership rules including `shouldRegister()` filtering. The command name stays `x402-mcp:list-tools` (rename deferred to v1).
- **Streaming receipt injection** — `X402CallTool` previously emitted streaming `Generator<JsonRpcResponse>` results without the `_meta["x402/payment-response"]` receipt. The terminal frame now carries the receipt (Auth / Authn / Validation thrown during iteration also surface a receipt-bearing terminal error frame; generic `Throwable`s still propagate per the README's "Post-settle tool failure" contract).
- **`laravel-x402` 0.3 → 0.5 bug-fix flow-through.** The bridge inherits `laravel-x402`'s registry-mutation fix, Octane spec leak, swallowed facilitator transport exceptions, unknown-asset fallback, and `.env` quoting fixes automatically.

### Bug fixes

- **`X402ReadResource::paymentRequiredResult` envelope** — the parent `ReadResource` serializable produces `{contents: [...]}` only and silently drops `isError` / `structuredContent`. The 402 challenge would have rendered as a non-error `contents` body. Fixed by emitting a custom payment-required serializable that produces the `{isError, structuredContent, content[0].text}` envelope shared with `X402CallTool`. Same fix on `X402GetPrompt`.
- **`X402Price::resolveFor`** widened from `Tool` to `Primitive` so the helper covers `Tool`, `Resource`, and `Prompt`. Existing callers were unaffected (Tool ⊂ Primitive).

**Full Changelog**: https://github.com/SanderMuller/laravel-x402-mcp/compare/0.1.0...0.2.0

## 0.1.0 - 2026-05-09

First release. Bridge between [`laravel/mcp`](https://github.com/laravel/mcp) (`^0.6 || ^0.7`) and [`sandermuller/laravel-x402`](https://github.com/sandermuller/laravel-x402) (`^0.2`) — gate Laravel MCP tools behind x402 stablecoin payments. Conformant with the x402 v2 MCP transport spec ([`specs/transports-v2/mcp.md`](https://github.com/coinbase/x402/blob/main/specs/transports-v2/mcp.md)): payment travels at the JSON-RPC level inside `params._meta["x402/payment"]`, never as an HTTP header.

### What's new

- **`#[X402Price]` attribute** — annotate any `Laravel\Mcp\Server\Tool` subclass with `#[X402Price(amount: '0.01', asset: 'USDC', network: 'base', payTo: '0x…')]`. `payTo` is optional and falls back to the global `x402.recipient` config. Network slugs and CAIP-2 strings both supported.
- **`WithX402Payment` trait** — drop-in for any `Laravel\Mcp\Server\Server` subclass. Registers two JSON-RPC method handlers:
  - `tools/list` → `X402ListTools` — advertises priced tools as `_meta["x402/price"]` (`{amount, asset, network[, payTo]}`) so agents discover prices before invoking and avoid wasted 402 round-trips.
  - `tools/call` → `X402CallTool` — verifies + settles via the bound `FacilitatorClient` for priced tools, runs free tools through the standard `CallTool`. On success, injects `result._meta["x402/payment-response"]` (settlement receipt). On failure, returns `result.isError = true` + `structuredContent: PaymentRequired` + `content[0].text` (JSON-stringified) per spec.
  - The trait hooks on `start()` *and* `handle()` (idempotent) so payment gating cannot be silently disabled by an unrelated `boot()` or `start()` override.
  
- **Replay protection** — nonces are claimed *before* the facilitator settles via `laravel-x402`'s `NonceStoreContract`; concurrent attack requests with the same authorization are rejected without hitting the facilitator.
- **`x402-mcp:list-tools` console command** — operator visibility into which tools are gated and at what price. Mirrors `x402:list-routes` from `laravel-x402`. Honors `shouldRegister()` so the listing matches what `tools/list` actually exposes.
- **Stdio + HTTP transport support** — `_meta["x402/payment"]` is a JSON-RPC field, not an HTTP envelope, so paid tools work on stdio as well as HTTP.
- **Testbench-backed test suite** — full HTTP round-trip coverage via `Mcp::web()` + `X402::fake()`, plus unit coverage for the trait, attribute reflection, list advertisement, replay rejection, and challenge shape.

### Notes

- **Requires PHP 8.2+, Laravel 11 or 12, `laravel/mcp` `^0.6 || ^0.7`, `sandermuller/laravel-x402` `^0.2`.** Configure `laravel-x402` first (`php artisan x402:install`) — this package inherits its facilitator wiring, recipient address, and asset config.
- **HTTP-level `PAYMENT-SIGNATURE` / `PAYMENT-RESPONSE` headers used by the x402 HTTP transport are NOT used for MCP** — payment travels at the JSON-RPC layer. See the wire-format table in the README.
- **Streamed tool responses** don't yet receive `_meta["x402/payment-response"]` injection — the receipt would need to land in the final chunk, which requires generator interception. Tracked as a v0.x follow-up.
- Public API is alpha; signatures may shift before `v1.0`.
