<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
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
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\CacheScope;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Laravel\Mcp\Server\ChallengeFactory;
use X402\Laravel\Mcp\Server\Concerns\PaymentGate;
use X402\Protocol\PaymentRequired;
use X402\Replay\NonceStoreContract;

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
 *
 * **Streaming receipt asymmetry:** unlike `X402CallTool`, this handler
 * does NOT wrap the resource's iterable. Mid-stream Throwables propagate
 * to vendor `toJsonRpcStreamedResponse`, which catches Auth/Authn/Validation
 * only — generic Throwables propagate past the receipt. Asymmetry mirrors
 * vendor `ReadResource` and is pinned by tests.
 */
final class X402ReadResource extends ReadResource implements Errable
{
    use PaymentGate;

    public function __construct(
        private readonly FacilitatorClient $facilitator,
        private readonly NonceStoreContract $nonceStore,
        private readonly PaidToolResponseCache $responseCache,
        private readonly ChallengeFactory $challenges,
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

        $resourceUri = is_string($uri) ? $uri : $resource->uri();

        return $this->runPaymentGate(
            $request,
            $resource,
            scopeFor: fn (Resource $r): CacheScope => CacheScope::forResourceRead($resourceUri),
            runWithReceipt: fn (Resource $r, SettleResult $s): Generator|JsonRpcResponse => $this->runResourceWithReceipt($request, $r, $resourceUri, $s),
            buildChallenge: fn (X402Price $p, Resource $r): PaymentRequired => $this->challenges->build(
                $p,
                // Resources are URI-addressed already; no `mcp://resource/` synthetic prefix
                // needed. The challenge resource is the request URI verbatim.
                $resourceUri,
                $p->asset . ' payment for MCP resource ' . $resourceUri,
            ),
            priceAbsentPassthrough: fn (): Generator|JsonRpcResponse => parent::handle($request, $context),
        );
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
            // No mid-stream wrapping (unlike X402CallTool) — vendor
            // toJsonRpcStreamedResponse catches Auth/Authn/Validation
            // only. Asymmetry mirrors vendor ReadResource and is
            // pinned by tests.
            /** @var iterable<Response|ResponseFactory|string> $response */
            return $this->toJsonRpcStreamedResponse(
                $request,
                $response,
                $this->streamingSerializable($this->serializable($resource, $uri), $receipt),
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
}
