<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Laravel\Mcp\Support\ValidationMessages;
use Throwable;
use X402\Errors\ErrorReason;
use X402\Exceptions\InvalidPaymentException;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\CacheScope;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Laravel\Mcp\Server\Concerns\PaymentGate;
use X402\Laravel\Support\ConfigReader;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Protocol\ResourceInfo;
use X402\Replay\NonceStoreContract;
use X402\Schemes\Evm\ExactScheme;
use X402\Schemes\Evm\NetworkRegistry;
use X402\Support\PriceParser;

/**
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\CallTool` that gates
 * tools annotated with `#[X402Price]` behind an x402 payment, conformant
 * with the x402 v2 MCP transport spec (`specs/transports-v2/mcp.md`).
 *
 * Wire format (v2 spec):
 *
 *   - Client → server: payment payload in `params._meta["x402/payment"]`
 *     (a full PaymentPayload envelope), NOT in any HTTP header. The HTTP
 *     `PAYMENT-SIGNATURE` header is HTTP-transport-only — MCP rides on
 *     JSON-RPC, payment travels at the JSON-RPC level.
 *
 *   - Server → client (payment required): tool result with
 *     `isError: true`, `structuredContent` carrying the PaymentRequired
 *     object, and `content[0].text` carrying the JSON-stringified version
 *     for clients that don't grok structuredContent.
 *
 *   - Server → client (settled): normal tool result with the receipt in
 *     `result._meta["x402/payment-response"]`.
 *
 * The MCP error code for "payment required" is the literal HTTP code 402
 * (per `MCP_PAYMENT_REQUIRED_CODE` in the TS reference), but we do not
 * emit a JSON-RPC error envelope for the payment-required case — we emit
 * a tool result with `isError: true`. JSON-RPC errors stay reserved for
 * actual protocol violations.
 *
 * **Idempotency note:** the nonce is claimed BEFORE the facilitator
 * settles. Concurrent attack requests with the same authorization are
 * rejected without hitting the facilitator. If the facilitator's settle
 * fails after the claim, the nonce is burned and the user must
 * regenerate.
 */
final class X402CallTool extends CallTool
{
    use PaymentGate;

    public function __construct(
        private readonly FacilitatorClient $facilitator,
        private readonly NonceStoreContract $nonceStore,
        private readonly Repository $config,
        private readonly PaidToolResponseCache $responseCache,
    ) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $tool = $this->resolveTool($request, $context);

        if (! $tool instanceof Tool) {
            return parent::handle($request, $context);
        }

        $price = $this->priceAttribute($tool);

        if (! $price instanceof X402Price) {
            return parent::handle($request, $context);
        }

        $challenge = $this->buildChallenge($price, $tool->name());
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

        // Idempotent retry — if a previous settled call's response was
        // dropped on the wire, the agent retries with the same signed
        // authorization. `lookup()` runs BEFORE `guardReplay()` so a
        // legitimate retry hits the cache instead of being rejected by
        // the nonce store. Scope binds method + tool + canonical-args
        // hash; signature binds forge-resistance via the EIP-3009
        // signature field.
        $scope = $this->scopeFor($request, $tool);
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

        $response = $this->runToolWithReceipt($request, $tool, $settle);

        // Streamed responses (`Generator`) cannot be cached — there's no
        // atomic snapshot to replay. Tool errors (`isError: true`) also
        // skip the cache: a tool that errored on a settled payment may
        // have transient state, and forcing the retry to re-hit a fresh
        // handler is safer than replaying the error body.
        if ($response instanceof JsonRpcResponse) {
            $body = $response->toArray();
            $resultPayload = $body['result'] ?? null;

            if (is_array($resultPayload) && ($resultPayload['isError'] ?? false) !== true) {
                $this->responseCache->store($scope, $signature, ['result' => $resultPayload]);
            }
        }

        return $response;
    }

    private function scopeFor(JsonRpcRequest $request, Tool $tool): CacheScope
    {
        // Pass the raw decoded array — including any integer keys produced
        // by `json_decode` from numeric-string JSON keys (`{"1":"a"}` →
        // `[1 => "a"]`). Filtering to string keys would alias distinct
        // numeric-keyed bags into the same hash and let a settled call
        // satisfy a retry against a different argument bag (Codex review
        // fix).
        $argsRaw = $request->params['arguments'] ?? [];
        $arguments = is_array($argsRaw) ? $argsRaw : [];

        return CacheScope::forToolCall($tool->name(), $arguments);
    }

    private function paymentRequiredResult(
        JsonRpcRequest $request,
        PaymentRequired $challenge,
        string $reason,
        ?string $errorReason = null,
    ): JsonRpcResponse {
        // Spec v2 §5.1: PaymentRequired body fields are
        // `{x402Version, resource, accepts, error?, extensions?}`. The
        // `errorReason` canonical enum lives on /verify and /settle response
        // shapes ONLY — putting it on the body here would be non-conformant.
        // Fold the canonical reason into the human-readable `error` field.
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

        // Spec mandates BOTH structuredContent (for x402-aware clients) AND
        // content[0].text (JSON-stringified, for clients that haven't
        // adopted structuredContent yet). `Response::error` sets isError: true.
        $factory = (new ResponseFactory(Response::error($textBody)))
            ->withStructuredContent($payload);

        // Use the trait's primitive-neutral serializable instead of
        // `$this->serializable($tool)` — keeps the 402 envelope shape
        // identical across all three handlers (Tool, Resource, Prompt).
        return $this->toJsonRpcResponse($request, $factory, $this->paymentRequiredSerializable());
    }

    /**
     * @return Generator<int, JsonRpcResponse>|JsonRpcResponse
     */
    private function runToolWithReceipt(JsonRpcRequest $request, Tool $tool, SettleResult $settle): Generator|JsonRpcResponse
    {
        // Replicates the parent's runtime call so we can wrap the response
        // before serialisation. Auth/validation exceptions are caught here
        // so settled-but-rejected payments don't leak the tool result —
        // same shape as the parent's catch block.
        try {
            $response = Container::getInstance()->call([$tool, 'handle']);
        } catch (AuthorizationException|AuthenticationException $authException) {
            $response = Response::error($authException->getMessage());
        } catch (ValidationException $validationException) {
            $response = Response::error(ValidationMessages::from($validationException));
        }

        $receipt = $this->buildReceipt($settle);

        if (is_iterable($response)) {
            // Streamed responses: the receipt rides on the terminal frame
            // because `toJsonRpcStreamedResponse` invokes `$serializable`
            // exactly once on the final `toJsonRpcResponse` call (see vendor
            // `Concerns/InteractsWithResponses.php:88`). `streamingSerializable`
            // wraps the parent's serializer to set `_meta["x402/payment-response"]`
            // on the factory before delegating.
            //
            // `wrapStreamingForReceipt` sits between the tool's iterable and
            // the upstream streaming method. It catches every Throwable from
            // iteration (except JsonRpcException, which is a transport-level
            // signal that must propagate as a JSON-RPC error envelope) and
            // yields a terminal Response::error so the upstream's terminal
            // toJsonRpcResponse still runs streamingSerializable and stamps
            // the receipt. Settled payments always emit settlement proof,
            // mirroring the non-streaming branch above and the README's
            // "Post-settle tool failure" guarantee.
            /** @var iterable<Response|ResponseFactory|string> $response */
            return $this->toJsonRpcStreamedResponse(
                $request,
                $this->wrapStreamingForReceipt($response),
                $this->streamingSerializable($tool, $receipt),
            );
        }

        if ($response instanceof ResponseFactory) {
            $factory = $response;
        } elseif ($response instanceof Response) {
            $factory = new ResponseFactory($response);
        } else {
            // Unexpected return type from $tool->handle() — produce an
            // error result so we never leak `mixed` into the wire.
            $factory = new ResponseFactory(Response::error('Tool returned unexpected response type.'));
        }

        $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

        return $this->toJsonRpcResponse($request, $factory, $this->serializable($tool));
    }

    /**
     * @param  array{success: true, transaction: string, network: string, payer: string}  $receipt
     * @return callable(ResponseFactory): array<string, mixed>
     */
    private function streamingSerializable(Tool $tool, array $receipt): callable
    {
        $base = $this->serializable($tool);

        return static function (ResponseFactory $factory) use ($base, $receipt): array {
            $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

            /** @var array<string, mixed> */
            return $base($factory);
        };
    }

    /**
     * Wrap the tool's iterable so any Throwable thrown mid-stream becomes a
     * terminal Response::error frame instead of propagating past the
     * streaming method. Lets the receipt always land on a settled payment.
     *
     * JsonRpcException is the explicit non-catch — it represents a
     * tool-authored protocol error that must surface as a JSON-RPC error
     * envelope, not a tool-result envelope.
     *
     * @param  iterable<Response|ResponseFactory|string>  $responses
     * @return Generator<int, Response|ResponseFactory|string>
     */
    private function wrapStreamingForReceipt(iterable $responses): Generator
    {
        try {
            foreach ($responses as $response) {
                yield $response;
            }
        } catch (JsonRpcException $jsonRpcException) {
            throw $jsonRpcException;
        } catch (ValidationException $validationException) {
            yield Response::error(ValidationMessages::from($validationException));
        } catch (Throwable $throwable) {
            yield Response::error($throwable->getMessage());
        }
    }

    private function resolveTool(JsonRpcRequest $request, ServerContext $context): ?Tool
    {
        $name = $request->params['name'] ?? null;
        if (! is_string($name)) {
            return null;
        }

        return $context->tools()->first(static fn (Tool $t): bool => $t->name() === $name);
    }

    private function priceAttribute(Tool $tool): ?X402Price
    {
        return X402Price::resolveFor($tool);
    }

    private function buildChallenge(X402Price $price, string $resource): PaymentRequired
    {
        $assetConfig = ConfigReader::array($this->config, 'x402.asset');
        $eip712Raw = $assetConfig['eip712'] ?? [];
        $eip712 = is_array($eip712Raw) ? $eip712Raw : [];

        $decimalsRaw = $assetConfig['decimals'] ?? 6;
        $decimals = is_int($decimalsRaw) ? $decimalsRaw : 6;
        $atomic = PriceParser::toAtomic($price->amount, $decimals);

        $address = $assetConfig['address'] ?? '';
        $assetAddress = is_string($address) ? $address : '';

        $name = $eip712['name'] ?? '';
        $version = $eip712['version'] ?? '2';

        return new PaymentRequired(
            scheme: 'exact',
            network: NetworkRegistry::toCaip2($price->network),
            amount: $atomic,
            asset: $assetAddress,
            payTo: $price->payTo ?? ConfigReader::string($this->config, 'x402.recipient'),
            maxTimeoutSeconds: ConfigReader::int($this->config, 'x402.max_timeout_seconds', 60),
            resource: 'mcp://tool/' . $resource,
            description: $price->asset . ' payment for MCP tool ' . $resource,
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
            ],
        );
    }
}
