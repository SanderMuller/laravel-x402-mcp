<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Laravel\Mcp\Support\ValidationMessages;
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
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\GetPrompt` that
 * gates prompts annotated with `#[X402Price]` behind an x402 payment.
 *
 * Mirrors `X402CallTool` and `X402ReadResource` for the JSON-RPC
 * `prompts/get` method. Wire format is identical:
 * `params._meta["x402/payment"]` carries the signed payload,
 * `result._meta["x402/payment-response"]` carries the settlement
 * receipt, and a payment-required failure returns a non-error
 * JSON-RPC `result` envelope with `isError: true` +
 * `structuredContent` (per the spec).
 *
 * **Why `Errable`:** vendor `Concerns/InteractsWithResponses::toJsonRpcResponse`
 * (lines 33-39) throws `JsonRpcException` whenever a non-`Errable`
 * method serializes a `Response::error(...)` body. `GetPrompt` does
 * not implement `Errable`, so this subclass adds the marker —
 * otherwise every 402 challenge for a paid prompt becomes a
 * JSON-RPC protocol-level `-32603`.
 *
 * **Prompt invocation:** `runPromptWithReceipt` invokes the prompt
 * via `Container::call([$prompt, 'handle'])`, mirroring vendor
 * `GetPrompt::handle:41` exactly. Prompts have no `HasUriTemplate`
 * or `AppResource` analogue, so the bare container call is the
 * correct contract — unlike resources, where `parent::invokeResource`
 * does additional template-variable / library-script setup.
 *
 * **Challenge resource:** `mcp://prompt/{name}`. Synthesised from
 * the prompt name (prompts are name-addressed, not URI-addressed
 * like resources).
 */
final class X402GetPrompt extends GetPrompt implements Errable
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
        $name = $request->get('name');

        try {
            $prompt = $this->resolvePrompt(is_string($name) ? $name : null, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Mirror the parent's behaviour: missing / unknown names
            // produce a JSON-RPC `-32602` error before x402 gating runs.
            throw new JsonRpcException($invalidArgumentException->getMessage(), -32602, $request->id);
        }

        $price = X402Price::resolveFor($prompt);

        if (! $price instanceof X402Price) {
            return parent::handle($request, $context);
        }

        $challenge = $this->buildChallenge($price, $prompt->name());
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

        // Idempotent retry — see X402CallTool::handle for the full
        // rationale. Lookup BEFORE guardReplay so a legitimate retry
        // hits the cache instead of being rejected by the nonce store.
        // Scope binds method + prompt URI + canonical-args hash so two
        // prompt fetches with different argument bags do not collide.
        $scope = $this->scopeFor($request, $prompt);
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

        $response = $this->runPromptWithReceipt($request, $prompt, $settle);

        // Streamed responses (`Generator`) cannot be cached — there's no
        // atomic snapshot to replay. Prompt errors (`isError: true`)
        // also skip the cache for the same transient-state argument as
        // tools and resources.
        if ($response instanceof JsonRpcResponse) {
            $body = $response->toArray();
            $resultPayload = $body['result'] ?? null;

            if (is_array($resultPayload) && ($resultPayload['isError'] ?? false) !== true) {
                $this->responseCache->store($scope, $signature, ['result' => $resultPayload]);
            }
        }

        return $response;
    }

    private function scopeFor(JsonRpcRequest $request, Prompt $prompt): CacheScope
    {
        // Pass the raw decoded array — see X402CallTool::scopeFor for the
        // numeric-key aliasing argument.
        $argsRaw = $request->params['arguments'] ?? [];
        $arguments = is_array($argsRaw) ? $argsRaw : [];

        return CacheScope::forPromptGet($prompt->name(), $arguments);
    }

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

        // GetPrompt's parent serializable produces `{description, messages}`
        // and does NOT surface `isError` / `structuredContent`. Same workaround
        // as `X402ReadResource::paymentRequiredResult`: emit a payment-required-
        // specific serializable so the 402 envelope shape is identical to
        // `X402CallTool`'s.
        return $this->toJsonRpcResponse($request, $factory, $this->paymentRequiredSerializable());
    }

    /**
     * @return Generator<int, JsonRpcResponse>|JsonRpcResponse
     */
    private function runPromptWithReceipt(JsonRpcRequest $request, Prompt $prompt, SettleResult $settle): Generator|JsonRpcResponse
    {
        // Mirrors vendor `GetPrompt::handle` line 41 exactly — a bare
        // `Container::call([$prompt, 'handle'])`. Prompts have no template
        // / app-resource setup, so the parent's invocation IS just this.
        try {
            // @phpstan-ignore-next-line argument.type — same shape as parent GetPrompt::handle:41
            $response = Container::getInstance()->call([$prompt, 'handle']);
        } catch (AuthorizationException|AuthenticationException $authException) {
            $response = Response::error($authException->getMessage());
        } catch (ValidationException $validationException) {
            $response = Response::error('Invalid params: ' . ValidationMessages::from($validationException));
        }

        $receipt = $this->buildReceipt($settle);

        if (is_iterable($response)) {
            // Same streaming-receipt convention as X402CallTool — see that
            // class's runToolWithReceipt for the coverage-limit invariant
            // (only Auth/Authn/Validation thrown DURING iteration get the
            // receipt; generic Throwables propagate).
            /** @var iterable<Response|ResponseFactory|string> $response */
            return $this->toJsonRpcStreamedResponse(
                $request,
                $response,
                $this->streamingSerializable($prompt, $receipt),
            );
        }

        if ($response instanceof ResponseFactory) {
            $factory = $response;
        } elseif ($response instanceof Response) {
            $factory = new ResponseFactory($response);
        } else {
            $factory = new ResponseFactory(Response::error('Prompt returned unexpected response type.'));
        }

        $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

        return $this->toJsonRpcResponse($request, $factory, $this->serializable($prompt));
    }

    /**
     * @param  array{success: true, transaction: string, network: string, payer: string}  $receipt
     * @return callable(ResponseFactory): array<string, mixed>
     */
    private function streamingSerializable(Prompt $prompt, array $receipt): callable
    {
        $base = $this->serializable($prompt);

        return static function (ResponseFactory $factory) use ($base, $receipt): array {
            $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

            /** @var array<string, mixed> */
            return $base($factory);
        };
    }

    private function buildChallenge(X402Price $price, string $promptName): PaymentRequired
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
            // Prompts are name-addressed; synthesise an `mcp://prompt/{name}`
            // resource URI so the challenge body has a stable identifier.
            resource: 'mcp://prompt/' . $promptName,
            description: $price->asset . ' payment for MCP prompt ' . $promptName,
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
            ],
        );
    }
}
