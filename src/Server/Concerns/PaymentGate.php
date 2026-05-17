<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Concerns;

use Closure;
use Generator;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Primitive;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Facilitator\SettleResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\CacheScope;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\ResourceInfo;
use X402\Schemes\Evm\ExactScheme;

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
 *   - `private readonly \X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache $responseCache`
 *     — for the cache lookup + store inside `runPaymentGate`.
 *
 * The trait reaches into `$this->nonceStore` / `$this->responseCache`
 * directly (PHP traits can't statically enforce constructor properties,
 * but the call sites are short and the contracts are one line). All
 * three handlers already match.
 */
trait PaymentGate
{
    private const META_REQUEST_KEY = 'x402/payment';

    private const META_RESPONSE_KEY = 'x402/payment-response';

    /**
     * Template method shared by all three handlers. Owns the post-resolve
     * 7-step gate: shape-verify → cache lookup → guardReplay → verify →
     * settle → run → conditional cache store.
     *
     * Order is load-bearing:
     *   1. **Cache lookup precedes `guardReplay`.** A legitimate retry of a
     *      previously settled call must hit the cache before the nonce
     *      store rejects the duplicate authorization.
     *   2. **Cache store skips `Generator` and `isError: true` results.**
     *      Streamed responses have no atomic snapshot; tool errors on a
     *      settled payment may carry transient state and deserve a fresh
     *      handler call on retry.
     *   3. **Snapshot shape is `['result' => $resultPayload]` exactly.**
     *      `PaidToolResponseCache::isValidSnapshot` rejects anything else.
     *
     * Target resolution stays in the handler — only `X402CallTool` has a
     * null-target fallback path; resource and prompt handlers throw
     * before ever calling this method, so the template only handles
     * the **price-not-present** passthrough.
     *
     * @template TPrimitive of Primitive
     * @param  TPrimitive                                                                                $target
     * @param  Closure(TPrimitive): CacheScope                                                           $scopeFor
     * @param  Closure(TPrimitive, SettleResult): (Generator<mixed, JsonRpcResponse>|JsonRpcResponse)   $runWithReceipt
     * @param  Closure(X402Price, TPrimitive): PaymentRequired                                           $buildChallenge
     * @param  Closure(): (Generator<mixed, JsonRpcResponse>|JsonRpcResponse)                            $priceAbsentPassthrough
     * @return Generator<mixed, JsonRpcResponse>|JsonRpcResponse
     */
    private function runPaymentGate(
        JsonRpcRequest $request,
        Primitive $target,
        Closure $scopeFor,
        Closure $runWithReceipt,
        Closure $buildChallenge,
        Closure $priceAbsentPassthrough,
    ): Generator|JsonRpcResponse {
        $price = X402Price::resolveFor($target);

        if (! $price instanceof X402Price) {
            return $priceAbsentPassthrough();
        }

        $challenge = $buildChallenge($price, $target);
        $paymentMeta = $this->readPaymentMeta($request);

        if ($paymentMeta === null) {
            return $this->paymentRequiredResult($request, $challenge, 'Payment required.');
        }

        try {
            $signature = PaymentSignature::fromArray($paymentMeta);
            (new ExactScheme())->verifyShape($signature, $challenge);
        } catch (InvalidPaymentException $invalidPaymentException) {
            return $this->paymentRequiredResult(
                $request,
                $challenge,
                $invalidPaymentException->getMessage(),
                $invalidPaymentException->reason?->value,
            );
        }

        $scope = $scopeFor($target);
        $cached = $this->responseCache->lookup($scope, $signature);

        if ($cached !== null) {
            /** @var array<string, mixed> $resultPayload */
            $resultPayload = $cached['result'];

            return JsonRpcResponse::result($request->id, $resultPayload);
        }

        try {
            $this->guardReplay($signature);
        } catch (InvalidPaymentException $invalidPaymentException) {
            return $this->paymentRequiredResult(
                $request,
                $challenge,
                $invalidPaymentException->getMessage(),
                $invalidPaymentException->reason?->value,
            );
        }

        $verify = $this->facilitator->verify($signature, $challenge);
        if (! $verify->isValid) {
            return $this->paymentRequiredResult(
                $request,
                $challenge,
                $verify->invalidReason ?? ErrorReason::UnexpectedVerifyError->value,
            );
        }

        $settle = $this->facilitator->settle($signature, $challenge);
        if (! $settle->success) {
            return $this->paymentRequiredResult(
                $request,
                $challenge,
                $settle->errorReason ?? ErrorReason::UnexpectedSettleError->value,
            );
        }

        $response = $runWithReceipt($target, $settle);

        if ($response instanceof JsonRpcResponse) {
            $body = $response->toArray();
            $resultPayload = $body['result'] ?? null;

            if (is_array($resultPayload) && ($resultPayload['isError'] ?? false) !== true) {
                $this->responseCache->store($scope, $signature, ['result' => $resultPayload]);
            }
        }

        return $response;
    }

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

        // Narrow to string-keyed entries to feed `PaymentSignature::fromArray`.
        // Runtime guarantee from `json_decode($s, true)` on an object input,
        // but PHPStan can't infer it from `mixed`.
        $out = [];
        foreach ($payment as $k => $v) {
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
     * Spec v2 §5.1: PaymentRequired body fields are
     * `{x402Version, resource, accepts, error?, extensions?}`. The
     * `errorReason` canonical enum lives on /verify and /settle response
     * shapes ONLY — putting it on the body here would be non-conformant.
     * Fold the canonical reason into the human-readable `error` field.
     *
     * Spec mandates BOTH structuredContent (for x402-aware clients) AND
     * content[0].text (JSON-stringified, for clients that haven't adopted
     * structuredContent yet). `Response::error` sets isError: true.
     *
     * Uses `paymentRequiredSerializable` so the 402 envelope shape is
     * identical across all three handlers (Tool, Resource, Prompt) —
     * the parent serializers for `ReadResource` and `GetPrompt` produce
     * `{contents: [...]}` and `{description, messages}` and would silently
     * drop `isError` / `structuredContent`.
     */
    private function paymentRequiredResult(
        JsonRpcRequest $request,
        PaymentRequired $challenge,
        string $reason,
        ?string $errorReason = null,
    ): JsonRpcResponse {
        $errorText = $errorReason !== null
            ? sprintf('%s (%s)', $reason, $errorReason)
            : $reason;

        $payload = [
            'x402Version' => 2,
            'error' => $errorText,
            'accepts' => [$challenge->toArrayV2()],
        ];

        $resourceInfo = $challenge->resourceInfo();
        if ($resourceInfo instanceof ResourceInfo) {
            $payload['resource'] = $resourceInfo->toArray();
        }

        $textBody = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $factory = (new ResponseFactory(Response::error($textBody)))
            ->withStructuredContent($payload);

        return $this->toJsonRpcResponse($request, $factory, $this->paymentRequiredSerializable());
    }

    /**
     * Wrap a parent-built serializable so the receipt rides on the
     * terminal frame of a streamed response.
     *
     * **Why `callable $base` instead of generic-on-`Primitive`.** The
     * vendor parent serializers have incompatible signatures —
     * `CallTool::serializable(Tool)`, `GetPrompt::serializable(Prompt)`,
     * `ReadResource::serializable(Resource, string)`. A trait method
     * taking `Primitive` cannot dispatch to those without `instanceof`
     * branching or PHPStan suppressions. Cleaner: handler computes
     * `$base = $this->serializable($primitive[, $uri])` at the call
     * site and passes the closure in. Trait stamps the receipt onto
     * the factory before delegating.
     *
     * @param  callable(ResponseFactory): array<string, mixed>                  $base
     * @param  array{success: true, transaction: string, network: string, payer: string} $receipt
     * @return callable(ResponseFactory): array<string, mixed>
     */
    private function streamingSerializable(callable $base, array $receipt): callable
    {
        return static function (ResponseFactory $factory) use ($base, $receipt): array {
            $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

            /** @var array<string, mixed> */
            return $base($factory);
        };
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
