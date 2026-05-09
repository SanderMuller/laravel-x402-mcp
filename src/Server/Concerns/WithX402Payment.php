<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Concerns;

use X402\Laravel\Mcp\Server\Methods\X402CallTool;
use X402\Laravel\Mcp\Server\Methods\X402ListTools;

/**
 * Drop-in trait for `Laravel\Mcp\Server\Server` subclasses. Registers two
 * JSON-RPC method handlers when the host server runs:
 *
 *   - `tools/list` → `X402ListTools` — advertises priced tools as
 *     `_meta["x402/price"]` so agents can discover prices before invoking.
 *   - `tools/call` → `X402CallTool` — gates any tool annotated with
 *     `#[X402Price]` behind a verified + settled x402 payment. Tools
 *     without `#[X402Price]` pass through to the standard `CallTool`.
 *
 * **Override-resilient by design.** PHP traits lose to subclass method
 * declarations, so a single hook point can be silently shadowed by a
 * downstream override. To keep payment gating from disappearing on a
 * custom `boot()`, `start()`, or both, the trait registers via two
 * independent entry points:
 *
 *   - `start()` — primary hook, runs once per server lifecycle.
 *   - `handle()` — safety net, runs once per JSON-RPC message. Catches
 *     subclasses that override `start()`. `addMethod()` is idempotent
 *     so the per-message cost is two array writes — well below noise.
 *
 * The only configuration that fully escapes the trait is a subclass that
 * overrides BOTH `start()` and `handle()` without calling `parent::*`.
 * That is an explicit, deep customization — not something a typical
 * `boot()` override would touch by accident.
 *
 * Users who *want* to opt out of trait defaults for a specific tool —
 * for example to register their own `tools/call` handler — should call
 * `addMethod()` from `boot()`; explicit registrations made there win
 * over the trait, because trait registration runs *before* `parent::start()`
 * (and `boot()` runs inside `parent::start()`).
 */
trait WithX402Payment
{
    public function start(): void
    {
        $this->bootX402Payment();

        parent::start();
    }

    public function handle(string $rawMessage): void
    {
        $this->bootX402Payment();

        parent::handle($rawMessage);
    }

    protected function bootX402Payment(): void
    {
        $this->addMethod('tools/list', X402ListTools::class);
        $this->addMethod('tools/call', X402CallTool::class);
    }
}
