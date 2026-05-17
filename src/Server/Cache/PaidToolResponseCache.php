<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Cache;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use X402\Protocol\PaymentSignature;
use X402\Server\IdempotencyKeyBuilder;
use X402\Support\JsonReader;

/**
 * Idempotent paid-response cache for the JSON-RPC transport. Closes the
 * "paid but didn't receive content" gap left open by `X402CallTool`'s
 * replay guard: when a transport drop loses the response between settle
 * and delivery, the agent retries the same signed authorization, and
 * `guardReplay` would otherwise reject the duplicate nonce with
 * `replay_attempt` — leaving the user paid without recourse.
 *
 * **Layered above the upstream `IdempotencyKeyBuilder`.** The hashing
 * lives in `php-x402` so PSR-15 (`PaymentResponseCache`) and JSON-RPC
 * (this adapter) produce keys with the same security properties. The
 * adapter contributes:
 *
 *   - The transport-specific scope (`[method, challengeResource, ...]`)
 *     via the `CacheScope` value object the caller passes in.
 *   - The forge-resistance binding: the EIP-3009 `signature` field
 *     extracted from the signed payload. JSON re-encoding is
 *     non-canonical, so we cannot bind to raw payload bytes; binding to
 *     the cryptographic signature gives the same forge-resistance.
 *   - A configurable, transport-namespaced prefix (`x402:idem:mcp:` by
 *     default) so HTTP and JSON-RPC consumers can co-exist on a shared
 *     Redis without colliding.
 *
 * **Cache value shape.** A `JsonRpcResponse` content array (the same
 * structure produced by `JsonRpcResponse::toArray()`), minus the
 * `id` — the new retry will carry a different `id` and the caller
 * rebinds it on replay.
 *
 * **Out of scope for v1.**
 *   - 402 challenges are not cached — retries must reprompt.
 *   - Streaming `Generator` responses are not cached — no atomic
 *     snapshot to rebuild.
 *   - Tool errors (`isError: true`) are not cached — a tool that
 *     errored on a settled payment may have transient state; retries
 *     deserve a fresh handler call. Callers gate this; the adapter
 *     itself just stores whatever it's handed.
 */
final readonly class PaidToolResponseCache
{
    public const DEFAULT_PREFIX = 'x402:idem:mcp:';

    private LoggerInterface $logger;

    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 3600,
        private string $prefix = self::DEFAULT_PREFIX,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Look up a previously-cached response for this `(scope, signature)`
     * pair. Returns the cached `JsonRpcResponse` content array on hit,
     * `null` on miss or invalid binding.
     *
     * The caller is responsible for rebinding the request `id` before
     * sending the cached body on the wire.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(CacheScope $scope, PaymentSignature $signature): ?array
    {
        $key = $this->keyFor($scope, $signature);

        if ($key === null) {
            return null;
        }

        // PSR-16 `get()` can throw on backend faults (Redis disconnected,
        // serialisation rejection, store-specific exceptions). Lookup
        // happens BEFORE settlement, so a thrown exception here would
        // crash the request as a -32603 JSON-RPC error and prevent the
        // user from completing payment. Degrade to "cache miss" instead
        // — the worst case is a duplicate facilitator round-trip on a
        // legitimate retry, not a request crash. Matches the store-side
        // resilience pattern at `store()`.
        try {
            $cached = $this->cache->get($key);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'x402-mcp: paid-response cache read threw — idempotent retry disabled for this request',
                [...$this->logContext($scope, $signature, $key), 'exception' => $throwable::class, 'message' => $throwable->getMessage()],
            );

            return null;
        }

        if (! is_array($cached)) {
            return null;
        }

        if (! $this->isValidSnapshot($cached)) {
            return null;
        }

        /** @var array<string, mixed> $cached */
        return $cached;
    }

    /**
     * Store a settled response under `(scope, signature)`. Caller must
     * filter out non-cacheable cases (streamed responses, tool errors,
     * 402 challenges) before invoking this method — the adapter does
     * not enforce those rules.
     *
     * @param  array<string, mixed>  $resultPayload  The `JsonRpcResponse`
     *                                              content array, minus `id`.
     */
    public function store(CacheScope $scope, PaymentSignature $signature, array $resultPayload): void
    {
        $key = $this->keyFor($scope, $signature);

        if ($key === null) {
            return;
        }

        // PSR-16 contract: `set()` returns false on failure (Redis down,
        // serialisation rejection, store-specific limits). Settlement has
        // already happened by the time `store` is called, so a silent
        // failure leaves the user paid with no replay snapshot — a
        // transport retry would still hit `replay_attempt`. Log a warning
        // so operators can detect broken idempotency without breaking
        // the request mid-flight (throwing here would surface a
        // post-settle failure as a 500 to the caller, which is worse
        // than a logged degraded-state warning).
        $context = $this->logContext($scope, $signature, $key);

        try {
            $ok = $this->cache->set($key, $resultPayload, $this->ttl);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'x402-mcp: paid-response cache write threw — idempotent retry disabled for this request',
                [...$context, 'exception' => $throwable::class, 'message' => $throwable->getMessage()],
            );

            return;
        }

        if (! $ok) {
            $this->logger->warning(
                'x402-mcp: paid-response cache write returned false — idempotent retry disabled for this request',
                $context,
            );
        }
    }

    private function keyFor(CacheScope $scope, PaymentSignature $signature): ?string
    {
        $auth = $signature->authorization();

        if ($auth === null) {
            return null;
        }

        // The forge-resistance pin — `signature` is the EIP-3009 cryptographic
        // signature over the authorization. Only the private-key holder can
        // produce it, so two different requests with the same `(network, from,
        // nonce)` tuple but different bytes hash to different keys.
        $signatureField = JsonReader::stringOrNull($signature->payload, 'signature');

        if ($signatureField === null || $signatureField === '') {
            return null;
        }

        return IdempotencyKeyBuilder::build(
            network: $signature->network,
            from: $auth['from'],
            nonce: $auth['nonce'],
            bindingBytes: strtolower($signatureField),
            scope: $scope->segments,
            prefix: $this->prefix,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(CacheScope $scope, PaymentSignature $signature, string $key): array
    {
        return [
            'key' => $key,
            'method' => $scope->segments[0] ?? null,
            'network' => $signature->network,
        ];
    }

    /**
     * Reject snapshots that don't carry the `result` envelope we serve
     * on replay. A poisoned cache entry falls through to the inner
     * handler instead of replaying garbage.
     *
     * @param  array<array-key, mixed>  $cached
     */
    private function isValidSnapshot(array $cached): bool
    {
        // Both checks together let callers index `$cached['result']` as
        // `array<string, mixed>` without re-narrowing — drops the
        // `is_array(... ?? null) ? ... : []` paranoia downstream.
        return isset($cached['result']) && is_array($cached['result']);
    }
}
