# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.0 - 2026-09-19

<!-- verified-sha: c1cee55eb13af947833a410cb80c0dbc9247c17b -->
Supports Laravel 13 and every `laravel/mcp` release from `0.7.1` to `1.0`. Until now the package only worked against `0.7.0`: `0.7.1` relocated the transport DTOs and the JSON-RPC exception out of `Laravel\Mcp\Server\*`, which made the gated method handlers fatal at class-load time on every later release. The settlement-receipt guarantee has also been made uniform across tools, resources and prompts, and independent of which `laravel/mcp` minor is installed. Tests pass on the CI matrix.

### What's new

- **Laravel 13** — `illuminate/support` is now `^12.0|^13.0`. This follows `sandermuller/laravel-x402` `0.8`, which moved to `illuminate/* ^12|^13` on PHP `^8.4`; `laravel/mcp` has allowed `illuminate ^13` since `0.7.1`, so the entire supported mcp range works on Laravel 13.
- **`laravel/mcp` `^0.7.1|^0.8|^0.9|^1.0`** — the handlers are written against the post-relocation namespaces (`Laravel\Mcp\Transport\JsonRpc*`, `Laravel\Mcp\Exceptions\JsonRpcException`), so a single set of signatures is valid across the whole range. `X402CallTool` composes `InteractsWithResponses` and declares its own tool serializer, because `1.0` moved both into `Laravel\Mcp\Server\ToolInvoker`. The CI matrix now runs a cell per supported minor.
- **The receipt lands on every post-settle failure, for all three primitives** — previously only `tools/call` wrapped a failing handler. A paid `resources/read` or `prompts/get` that threw mid-stream let the exception propagate past the receipt, so the client had no proof of a payment that had already settled on-chain. All three handlers now route through the shared wrapper, on both the synchronous and the streaming path. `JsonRpcException` still escapes as a JSON-RPC error envelope.

### Behaviour changes

- **Post-settle exception messages are redacted outside debug mode.** Validation and authorization failures keep their message; any other exception is reported through the application's exception handler and replaced with `'An internal server error occurred.'` unless `app.debug` is on. This matches what `laravel/mcp` `1.0` does for unpaid calls, and prevents a paid call from echoing internal exception text.
- **A settled-but-failed call is never cached.** The `resources/read` and `prompts/get` serializers emit no `isError` key, so the idempotency cache could not distinguish a failed paid call from a successful one and stored it — a later retry of that authorization was served the stored failure as though it had succeeded. Such calls are now excluded explicitly, matching what priced tools already did.
- **Paid resource and prompt streams no longer propagate generic mid-stream exceptions.** On `laravel/mcp` `0.9` and below this was the documented behaviour; it is now a terminal error frame carrying the receipt, the same shape `tools/call` produced.

### Requirements

- **PHP `^8.4`** (was `^8.3`) and **Laravel 12 or 13** (was 11 or 12). Both drops come from `sandermuller/laravel-x402` `0.8`, which this release requires: it no longer resolves on PHP 8.3 or Laravel 11. Staying on Laravel 11 or PHP 8.3 means staying on `0.3.0`.
- **`sandermuller/laravel-x402` `^0.8`** (was `^0.5`).
- Laravel 12 remains supported in `require`, but the CI matrix exercises Laravel 13 only: Pest 5 needs `symfony/process ^8.1` and Testbench 10 pins `^7.2`, so the pair cannot install together. Laravel 12 breakage is still treated as a bug — report it.

### Notes

- Minimum `laravel/mcp` is `0.7.1`. `0.7.0` and `0.6.x` are no longer supported — `0.7.0` predates the namespace relocation this release standardises on.
- On `laravel/mcp` `1.0+`, `#[Cacheable]` emits client-facing cache hints. Do not mark a priced resource, or a server hosting one, as `CacheScope::Public`: a paid result advertised as publicly cacheable can be replayed by intermediaries without payment. See the README.
- No API signatures changed. `WithX402Payment` users need no caller change.
- Public API is alpha; signatures may shift before `v1.0`.

### Maintenance

- The repo moved to the canonical package tooling: Pest 5 with the first-party `pest-plugin-rector` / `-phpstan` / `-agent` (replacing `mrpunyapal/rector-pest`), Testbench `^11`, `symplify/phpstan-rules` in place of the abandoned `symplify/phpstan-extensions`, and `sandermuller/package-boost-laravel` in place of `package-boost`. None of it reaches the published archive.
- A zizmor GitHub Actions audit now runs on workflow changes, and the workflows were hardened to pass it (`persist-credentials: false` on the checkouts that never push, a Dependabot cooldown so a bump cannot land the day a release is published).
- The `.gitattributes` managed block was empty, so `.ai/`, `.claude/` and `.config/` were shipping inside the Composer archive. Regenerated.
- PHPStan and the test matrix now also run on `composer.json` / `composer.lock` and `testbench.yaml` changes — two dependency-only breakages reached `main` because the path filters watched neither.
- Dropped the abandoned `rector/type-perfect`, whose rules `tomasvotruba/type-coverage` `2.3` absorbed; installing both registered every rule twice and aborted the analysis.
- Removed the `PackageBoostServiceProvider` entry from `testbench.yaml`; `package-boost` `0.15.2` no longer ships that class.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402-mcp/compare/0.3.0...0.4.0

## 0.3.0 - 2026-05-10

### 0.3.0

Extends price discovery from `tools/list` to all three gated primitives — agents now see `_meta["x402/price"]` on `resources/list` and `prompts/list` too, so they can budget across tools, paid resources, and paid prompts in the same listing pass without a wasted 402 round-trip on the first call. Tests pass on the CI matrix.

#### What's new

- **Price discovery on `resources/list` and `prompts/list`** — `WithX402Payment` now registers six method handlers (the previous four plus `X402ListResources` and `X402ListPrompts`). Each priced `Resource` / `Prompt` carries `_meta["x402/price"]` with the same `{amount, asset, network[, payTo]}` envelope as `tools/list`. Free entries pass through unchanged. Resource templates are filtered by the parent `ServerContext::resources()` and listed under `resources/templates/list` — pricing on a template still gates every concrete URI.
- **`X402Price` public surface — `META_KEY` + `toMetaArray()`** — `'x402/price'` is now `X402Price::META_KEY`, single source for downstream code that builds or reads the meta envelope. The new `toMetaArray()` instance method emits the wire-format block (omitting `payTo` when not overridden).

#### Internal improvements

- **`AdvertisesX402Price` trait** consolidates the annotate-then-paginate body across `X402ListTools`, `X402ListResources`, `X402ListPrompts`. Each handler is now a one-line `handle()` selecting its primitive collection + paginator label; the per-class `META_KEY` constant and `annotatePrice` body have been removed.
- **Shared base test fixtures** (`PaidEcho*` / `FreeEcho*` for `Tool` / `Resource` / `Prompt`) moved into `tests/Support/X402TestHelpers.php`. Each list-handler test now runs in isolation — previously, single-file pest runs failed because the fixtures lived in a sibling handler test.

**Full Changelog**: https://github.com/SanderMuller/laravel-x402-mcp/compare/0.2.0...0.3.0

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
