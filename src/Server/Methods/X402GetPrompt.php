<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Server\Methods;

use Generator;
use Illuminate\Container\Container;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Contracts\Errable;
use Laravel\Mcp\Server\Methods\GetPrompt;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
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
 *
 * **Catch-set deviation from vendor parent:** this handler routes the
 * prompt through `PaymentGate::invokeForReceipt`, which turns every
 * Throwable except `JsonRpcException` into an error result carrying the
 * receipt. Vendor's own catch set moved across minors (<= 0.9 caught
 * `ValidationException` here, >= 1.0 catches every Throwable in
 * `callHandler`); owning it keeps the settled-payment behaviour
 * identical on all of them.
 *
 * **Streaming receipts:** like `X402CallTool`, this handler wraps the
 * primitive's iterable via `wrapStreamingForReceipt`, so a settled
 * payment always emits `result._meta["x402/payment-response"]` — even
 * when the handler throws mid-stream. Vendor's streaming catch set
 * changed across `laravel/mcp` minors; the wrapper makes the receipt
 * guarantee independent of it.
 */
final class X402GetPrompt extends GetPrompt implements Errable
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
        $name = $request->get('name');

        try {
            $prompt = $this->resolvePrompt(is_string($name) ? $name : null, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Mirror the parent's behaviour: missing / unknown names
            // produce a JSON-RPC `-32602` error before x402 gating runs.
            throw new JsonRpcException($invalidArgumentException->getMessage(), -32602, $request->id);
        }

        return $this->runPaymentGate(
            $request,
            $prompt,
            scopeFor: fn (Prompt $p): CacheScope => CacheScope::forPromptGet($p->name(), $this->arguments($request)),
            runWithReceipt: fn (Prompt $p, SettleResult $s): Generator|JsonRpcResponse => $this->runPromptWithReceipt($request, $p, $s),
            buildChallenge: fn (X402Price $price, Prompt $p): PaymentRequired => $this->challenges->build(
                $price,
                // Prompts are name-addressed; synthesise an `mcp://prompt/{name}`
                // resource URI so the challenge body has a stable identifier.
                'mcp://prompt/' . $p->name(),
                $price->asset . ' payment for MCP prompt ' . $p->name(),
            ),
            priceAbsentPassthrough: fn (): Generator|JsonRpcResponse => parent::handle($request, $context),
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arguments(JsonRpcRequest $request): array
    {
        // Pass the raw decoded array — see X402CallTool::arguments for the
        // numeric-key aliasing argument.
        $argsRaw = $request->params['arguments'] ?? [];

        return is_array($argsRaw) ? $argsRaw : [];
    }

    /**
     * @return Generator<int, JsonRpcResponse>|JsonRpcResponse
     */
    private function runPromptWithReceipt(JsonRpcRequest $request, Prompt $prompt, SettleResult $settle): Generator|JsonRpcResponse
    {
        // Mirrors vendor `GetPrompt::handle` line 41 exactly — a bare
        // `Container::call([$prompt, 'handle'])`. Prompts have no template
        // / app-resource setup, so the parent's invocation IS just this.
        // @phpstan-ignore-next-line argument.type — same shape as vendor GetPrompt
        $response = $this->invokeForReceipt(fn (): mixed => Container::getInstance()->call([$prompt, 'handle']));

        $receipt = $this->buildReceipt($settle);

        if (is_iterable($response)) {
            // Wrapped exactly like X402CallTool: a settled payment always
            // emits its receipt, even when the prompt throws mid-stream.
            // Vendor's own catch set differs between laravel/mcp minors
            // (<= 0.9 catches Auth/Authn/Validation, >= 1.0 catches every
            // Throwable), so the wrapper is what keeps the receipt
            // guarantee version-independent.
            /** @var iterable<Response|ResponseFactory|string> $response */
            return $this->toJsonRpcStreamedResponse(
                $request,
                $this->wrapStreamingForReceipt($response),
                $this->streamingSerializable($this->serializable($prompt), $receipt),
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
}
