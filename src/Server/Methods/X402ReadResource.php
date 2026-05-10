<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\ReadResource;
use Laravel\Mcp\Server\Resource;
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
 * Drop-in replacement for `Laravel\Mcp\Server\Methods\ReadResource` that
 * gates resources annotated with `#[X402Price]` behind an x402 payment.
 *
 * Mirrors `X402CallTool` for the JSON-RPC `resources/read` method. Wire
 * format is identical: `params._meta["x402/payment"]` carries the
 * signed payload, `result._meta["x402/payment-response"]` carries the
 * settlement receipt, and a payment-required failure returns a
 * non-error JSON-RPC `result` envelope with `isError: true` +
 * `structuredContent` (per the spec).
 *
 * **Why `Errable`:** vendor `Concerns/InteractsWithResponses::toJsonRpcResponse`
 * (lines 33-39) throws `JsonRpcException` whenever a non-`Errable`
 * method serializes a `Response::error(...)` body. `CallTool` already
 * implements `Errable` so the `tools/call` 402 path works; `ReadResource`
 * does not, so this subclass adds the marker. Without it every 402
 * challenge for a paid resource becomes a JSON-RPC protocol-level
 * `-32603`, not the spec-mandated tool-result-with-isError envelope.
 *
 * **Resource invocation:** `runResourceWithReceipt` delegates to
 * `parent::invokeResource($resource, $uri)` rather than reimplementing
 * the call as a bare `Container::call([$resource, 'handle'])`. The
 * parent helper seeds `Laravel\Mcp\Request` with the concrete URI,
 * merges `HasUriTemplate` variables into the request, and installs
 * `mcp.library_scripts` for `AppResource`s; reimplementing would
 * silently break templated and app-resource handlers under x402 gating.
 */
final class X402ReadResource extends ReadResource implements Errable
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
        $uri = $request->get('uri');

        try {
            $resource = $this->resolveResource(is_string($uri) ? $uri : null, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Mirror the parent's behaviour: missing / unknown URIs
            // produce a JSON-RPC `-32002` error before x402 gating runs.
            throw new JsonRpcException($invalidArgumentException->getMessage(), -32002, $request->id);
        }

        $price = X402Price::resolveFor($resource);

        if (! $price instanceof X402Price) {
            return parent::handle($request, $context);
        }

        $resourceUri = is_string($uri) ? $uri : $resource->uri();
        $challenge = $this->buildChallenge($price, $resourceUri);
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
        // Scope binds method + concrete URI; signature binds
        // forge-resistance. Resource URIs are already concrete (the
        // request carried them) so two reads under the same priced
        // template never collide.
        $scope = CacheScope::forResourceRead($resourceUri);
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

        $response = $this->runResourceWithReceipt($request, $resource, $resourceUri, $settle);

        // Streamed responses (`Generator`) cannot be cached — there's no
        // atomic snapshot to replay. Resource errors (`isError: true`)
        // also skip the cache: a resource that errored on a settled
        // payment may have transient state, and forcing the retry to
        // re-hit a fresh handler is safer than replaying the error body.
        if ($response instanceof JsonRpcResponse) {
            $body = $response->toArray();
            $resultPayload = $body['result'] ?? null;

            if (is_array($resultPayload) && ($resultPayload['isError'] ?? false) !== true) {
                $this->responseCache->store($scope, $signature, ['result' => $resultPayload]);
            }
        }

        return $response;
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

        // ReadResource's parent serializable produces `{contents: [...]}`
        // and does NOT surface `isError` / `structuredContent` from the
        // factory — the 402 envelope would be silently dropped if we
        // reused it. Use a payment-required-specific serializable that
        // emits the same `{isError, structuredContent, content[0].text}`
        // shape as `X402CallTool::paymentRequiredResult`, so the wire
        // format for a 402 challenge is identical regardless of which
        // primitive was invoked.
        return $this->toJsonRpcResponse($request, $factory, $this->paymentRequiredSerializable());
    }

    /**
     * @return Generator<int, JsonRpcResponse>|JsonRpcResponse
     */
    private function runResourceWithReceipt(JsonRpcRequest $request, Resource $resource, string $uri, SettleResult $settle): Generator|JsonRpcResponse
    {
        // Delegate to the parent's helper so HasUriTemplate variables are
        // merged into Laravel\Mcp\Request, AppResource library scripts are
        // installed, and the URI is bound on the request — none of that
        // happens with a bare `Container::call([$resource, 'handle'])`.
        //
        // Vendor `ReadResource::handle` only catches `ValidationException`
        // around `invokeResource`; mirror that here. Auth/Authn exceptions
        // are not caught (unlike the tool path) — they propagate to
        // Server::handle and become a JSON-RPC -32603. Same invariant the
        // parent ReadResource ships.
        try {
            $response = $this->invokeResource($resource, $uri);
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
                $this->streamingSerializable($resource, $uri, $receipt),
            );
        }

        if ($response instanceof ResponseFactory) {
            $factory = $response;
        } elseif ($response instanceof Response) {
            $factory = new ResponseFactory($response);
        } else {
            $factory = new ResponseFactory(Response::error('Resource returned unexpected response type.'));
        }

        $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

        return $this->toJsonRpcResponse($request, $factory, $this->serializable($resource, $uri));
    }

    /**
     * @param  array{success: true, transaction: string, network: string, payer: string}  $receipt
     * @return callable(ResponseFactory): array<string, mixed>
     */
    private function streamingSerializable(Resource $resource, string $uri, array $receipt): callable
    {
        $base = $this->serializable($resource, $uri);

        return static function (ResponseFactory $factory) use ($base, $receipt): array {
            $factory->withMeta(self::META_RESPONSE_KEY, $receipt);

            /** @var array<string, mixed> */
            return $base($factory);
        };
    }

    private function buildChallenge(X402Price $price, string $resourceUri): PaymentRequired
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
            // Resources are URI-addressed already; no `mcp://resource/` synthetic prefix
            // needed. The challenge resource is the request URI verbatim.
            resource: $resourceUri,
            description: $price->asset . ' payment for MCP resource ' . $resourceUri,
            extra: [
                'name' => is_string($name) ? $name : '',
                'version' => is_string($version) ? $version : '2',
            ],
        );
    }
}
