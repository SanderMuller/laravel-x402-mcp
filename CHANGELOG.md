# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
