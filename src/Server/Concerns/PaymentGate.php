<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Concerns;

use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Facilitator\SettleResult;
use X402\Protocol\PaymentSignature;

/**
 * Shared payment-flow primitives used by all three method handlers
 * (`X402CallTool`, `X402ReadResource`, `X402GetPrompt`).
 *
 * **Why a trait, not a base class.** Each handler extends a different
 * `laravel/mcp` parent (`CallTool`, `ReadResource`, `GetPrompt`); a
 * shared base can't sit between them and the parent. A trait composes
 * with the existing inheritance chain and lets each handler keep its
 * primitive-specific bits (challenge URI synthesis, run-with-receipt
 * dispatch).
 *
 * **Required host shape.** Hosts using this trait must declare:
 *
 *   - `private readonly \X402\Replay\NonceStoreContract $nonceStore`
 *     — for `guardReplay`.
 *
 * The trait reaches into `$this->nonceStore` directly (PHP traits can't
 * statically enforce constructor properties, but the call site is short
 * and the contract is one line). All three handlers already match.
 */
trait PaymentGate
{
    private const META_REQUEST_KEY = 'x402/payment';

    private const META_RESPONSE_KEY = 'x402/payment-response';

    /**
     * @return array<string, mixed>|null
     */
    private function readPaymentMeta(JsonRpcRequest $request): ?array
    {
        $meta = $request->params['_meta'] ?? null;
        if (! is_array($meta)) {
            return null;
        }

        $payment = $meta[self::META_REQUEST_KEY] ?? null;
        if (! is_array($payment)) {
            return null;
        }

        return $this->stringKeyed($payment);
    }

    /**
     * Narrow a JSON-decoded array to string-keyed entries — runtime
     * guarantee from `json_decode($s, true)` on an object input, but
     * PHPStan can't infer it from `mixed`.
     *
     * Used to feed `PaymentSignature::fromArray` which requires
     * `array<string, mixed>`. NOT used for argument-bag canonicalisation
     * (cache-scope hashing) — that path preserves integer keys to avoid
     * cross-bag aliasing.
     *
     * @param  array<int|string, mixed>  $arr
     * @return array<string, mixed>
     */
    private function stringKeyed(array $arr): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Claim the payment's nonce against the shared store. Throws
     * `InvalidPaymentException(ReplayAttempt)` on a duplicate.
     *
     * Reads `from` / `nonce` / `validBefore` via
     * `$signature->authorization()` — the upstream `php-x402` 0.4
     * helper that hoists the EIP-3009 authorization-tuple extraction
     * out of inline `JsonReader` walks.
     */
    private function guardReplay(PaymentSignature $signature): void
    {
        $auth = $signature->authorization();

        if ($auth === null) {
            // Missing / empty `from` or `nonce` — `verifyShape` will have
            // already rejected this for the EVM exact scheme; keep this
            // as a defensive guard for future schemes that defer to
            // authorization().
            return;
        }

        $ttl = max(60, $auth['validBefore'] - Date::now()->getTimestamp() + 30);

        if (! $this->nonceStore->claim($signature->network, $auth['from'], $auth['nonce'], $ttl)) {
            throw InvalidPaymentException::with(
                ErrorReason::ReplayAttempt,
                'Nonce already used (replay attempt).',
            );
        }
    }

    /**
     * @return array{success: true, transaction: string, network: string, payer: string}
     */
    private function buildReceipt(SettleResult $settle): array
    {
        return [
            'success' => true,
            'transaction' => $settle->transaction,
            'network' => $settle->network,
            'payer' => $settle->payer,
        ];
    }

    /**
     * Serializable callback for the 402 challenge envelope. Emits
     * `{isError, structuredContent, content[0].text}` regardless of
     * which primitive (Tool / Resource / Prompt) was invoked, so
     * agents see the same error shape across all three gated methods.
     *
     * **Why a custom serializable.** The parent serializables for
     * `ReadResource` and `GetPrompt` produce `{contents: [...]}` and
     * `{description, messages}` respectively — neither surfaces
     * `isError` or `structuredContent` from the factory, so reusing
     * them would silently drop the 402 envelope. `CallTool`'s parent
     * serializable does surface those fields, but using the custom
     * one here keeps the wire format identical across all three
     * handlers (and matches what the spec mandates).
     *
     * @return callable(ResponseFactory): array<string, mixed>
     */
    private function paymentRequiredSerializable(): callable
    {
        return static fn (ResponseFactory $factory): array => $factory->mergeStructuredContent(
            $factory->mergeMeta([
                'content' => $factory->responses()->map(static fn (Response $response): array => [
                    'type' => 'text',
                    'text' => (string) $response->content(),
                ])->all(),
                'isError' => $factory->responses()->contains(static fn (Response $response): bool => $response->isError()),
            ])
        );
    }
}
