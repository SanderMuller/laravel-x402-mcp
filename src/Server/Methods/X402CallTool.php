<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
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
 *
 * **Streaming receipt asymmetry:** unlike `X402ReadResource` and
 * `X402GetPrompt`, this handler wraps the tool's iterable via
 * `wrapStreamingForReceipt` so settled payments still emit a receipt
 * even when the tool throws mid-stream. The other two handlers rely on
 * vendor `toJsonRpcStreamedResponse`, which only catches
 * Auth/Authn/Validation. Asymmetry is intentional and pinned by tests.
 */
final class X402CallTool extends CallTool
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
        $tool = $this->resolveTool($request, $context);

        if (! $tool instanceof Tool) {
            return parent::handle($request, $context);
        }

        return $this->runPaymentGate(
            $request,
            $tool,
            scopeFor: fn (Tool $t): CacheScope => CacheScope::forToolCall($t->name(), $this->arguments($request)),
            runWithReceipt: fn (Tool $t, SettleResult $s): Generator|JsonRpcResponse => $this->runToolWithReceipt($request, $t, $s),
            buildChallenge: fn (X402Price $p, Tool $t): PaymentRequired => $this->challenges->build(
                $p,
                'mcp://tool/' . $t->name(),
                $p->asset . ' payment for MCP tool ' . $t->name(),
            ),
            priceAbsentPassthrough: fn (): Generator|JsonRpcResponse => parent::handle($request, $context),
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arguments(JsonRpcRequest $request): array
    {
        // Pass the raw decoded array — including any integer keys produced
        // by `json_decode` from numeric-string JSON keys (`{"1":"a"}` →
        // `[1 => "a"]`). Filtering to string keys would alias distinct
        // numeric-keyed bags into the same hash and let a settled call
        // satisfy a retry against a different argument bag.
        $argsRaw = $request->params['arguments'] ?? [];

        return is_array($argsRaw) ? $argsRaw : [];
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
                $this->streamingSerializable($this->serializable($tool), $receipt),
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
}
