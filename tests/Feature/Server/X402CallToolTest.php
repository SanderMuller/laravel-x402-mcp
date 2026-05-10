<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Laravel\Mcp\Server\Methods\X402CallTool;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Replay\InMemoryNonceStore;
use X402\Replay\NonceStoreContract;

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidEchoTool extends Tool
{
    public function description(): string
    {
        return 'Paid echo tool for tests.';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['echo' => 'paid']);
    }
}

final class FreeEchoTool extends Tool
{
    public function description(): string
    {
        return 'Free echo tool for tests.';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['echo' => 'free']);
    }
}

final class FreeUnauthorizedTool extends Tool
{
    public function description(): string
    {
        return 'Free tool that throws AuthorizationException for tests.';
    }

    public function handle(Request $request): Response
    {
        throw new AuthorizationException('not allowed by parent');
    }
}

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidUnauthorizedTool extends Tool
{
    public function description(): string
    {
        return 'Paid tool that throws AuthorizationException for tests.';
    }

    public function handle(Request $request): Response
    {
        throw new AuthorizationException('not allowed after settle');
    }
}

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingTool extends Tool
{
    public function description(): string
    {
        return 'Paid streaming tool that yields a notification then a final json frame.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('progress', ['percent' => 50]);

        yield Response::json(['echo' => 'streamed']);
    }
}

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidNotificationsOnlyTool extends Tool
{
    public function description(): string
    {
        return 'Paid streaming tool that yields only notifications and never reaches a terminal data frame.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('progress', ['percent' => 25]);

        yield Response::notification('progress', ['percent' => 75]);
    }
}

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingThrowsTool extends Tool
{
    public function description(): string
    {
        return 'Paid streaming tool that yields one notification then throws AuthorizationException mid-stream.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('progress', ['percent' => 50]);

        throw new AuthorizationException('mid-stream auth fail');
    }
}

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingThrowsRuntimeTool extends Tool
{
    public function description(): string
    {
        return 'Paid streaming tool that yields one notification then throws a generic RuntimeException — pins the documented receipt-loss invariant.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(Request $request): Generator
    {
        yield Response::notification('progress', ['percent' => 50]);

        throw new RuntimeException('mid-stream generic failure');
    }
}

// `StubFacilitator` lives in `tests/Support/X402TestHelpers.php` so it can be
// shared with `X402ReadResourceTest` (and future `X402GetPromptTest`).

/**
 * Counts verify + settle invocations so cache-hit tests can assert the
 * facilitator was NOT called on the retry path.
 */
final class CountingFacilitator implements FacilitatorClient
{
    public int $verifyCalls = 0;

    public int $settleCalls = 0;

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        ++$this->verifyCalls;

        return new VerifyResult(true, null, '0xpayer');
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        ++$this->settleCalls;

        return new SettleResult(true, '0xtxhash', $challenge->network, '0xpayer');
    }

    public function supported(): SupportedKinds
    {
        return new SupportedKinds(kinds: []);
    }

    public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
    {
        return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
    }
}

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class OtherPaidTool extends Tool
{
    public function description(): string
    {
        return 'Second priced tool — used for cross-scope replay-rejection tests.';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['echo' => 'other']);
    }
}

/**
 * @param  list<Tool>  $tools
 */
function makeServerContext(array $tools): ServerContext
{
    return new ServerContext(
        supportedProtocolVersions: ['2025-11-25'],
        serverCapabilities: [],
        serverName: 'test',
        serverVersion: '0.0.1',
        instructions: '',
        maxPaginationLength: 50,
        defaultPaginationLength: 15,
        tools: $tools,
        resources: [],
        prompts: [],
    );
}

function makeCallTool(
    ?FacilitatorClient $facilitator = null,
    ?NonceStoreContract $store = null,
    ?PaidToolResponseCache $cache = null,
): X402CallTool {
    return new X402CallTool(
        $facilitator ?? new StubFacilitator(),
        $store ?? new InMemoryNonceStore(),
        config: resolve(Repository::class),
        responseCache: $cache ?? resolve(PaidToolResponseCache::class),
    );
}

/**
 * @param  array<string, mixed>  $extraParams
 */
function makeJsonRpcRequest(string $toolName, array $extraParams = []): JsonRpcRequest
{
    return new JsonRpcRequest(
        id: 1,
        method: 'tools/call',
        params: array_merge(['name' => $toolName, 'arguments' => []], $extraParams),
    );
}

// `expectedReceipt()` lives in `tests/Support/X402TestHelpers.php`.

/**
 * Narrow a `Generator|JsonRpcResponse` from `X402CallTool::handle` to a
 * frame list. PHPStan can't see through Pest's `expect(...)->toBeInstanceOf(...)`,
 * so this guard keeps the assertion explicit and the phpstan output clean.
 *
 * @param  Generator<mixed, JsonRpcResponse, mixed, mixed>|JsonRpcResponse  $result
 * @return list<JsonRpcResponse>
 */
function streamFrames(Generator|JsonRpcResponse $result): array
{
    if (! $result instanceof Generator) {
        throw new RuntimeException('Expected a streaming Generator response, got JsonRpcResponse.');
    }

    /** @var list<JsonRpcResponse> */
    return iterator_to_array($result, preserve_keys: false);
}

// `buildPaymentMeta()` lives in `tests/Support/X402TestHelpers.php`.

it('returns a tool result with isError + structuredContent when no x402/payment meta is present', function (): void {
    $rpcRequest = makeJsonRpcRequest('paid-echo-tool');

    $response = makeCallTool()->handle($rpcRequest, makeServerContext([new PaidEchoTool()]));

    $result = $response->toArray()['result'] ?? null;
    expect($result)->toBeArray();

    /** @var array<string, mixed> $resultArr */
    $resultArr = $result;
    expect($resultArr['isError'] ?? null)->toBeTrue();

    $structured = $resultArr['structuredContent'] ?? null;
    expect($structured)->toBeArray();

    /** @var array<string, mixed> $structuredArr */
    $structuredArr = $structured;
    expect($structuredArr['x402Version'] ?? null)->toBe(2)
        ->and($structuredArr['error'] ?? null)->toBe('Payment required.')
        ->and($structuredArr['accepts'] ?? null)->toHaveCount(1);
});

it('passes free tools through to the parent CallTool without consulting the facilitator', function (): void {
    $facilitator = new class implements FacilitatorClient {
        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            throw new RuntimeException('facilitator must not be called for free tools');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            throw new RuntimeException('facilitator must not be called for free tools');
        }

        public function supported(): SupportedKinds
        {
            return new SupportedKinds(kinds: []);
        }

        public function discoverResources(DiscoveryQuery $query = new DiscoveryQuery()): DiscoveryPage
        {
            return new DiscoveryPage(items: [], limit: $query->limit, offset: $query->offset, total: 0);
        }
    };

    $response = makeCallTool($facilitator)->handle(makeJsonRpcRequest('free-echo-tool'), makeServerContext([new FreeEchoTool()]));

    $result = $response->toArray()['result'] ?? null;
    expect($result)->toBeArray();

    /** @var array<string, mixed> $resultArr */
    $resultArr = $result;
    expect($resultArr['isError'] ?? false)
        ->toBeFalse();
});

it('settles and injects x402/payment-response into result._meta on a valid signature', function (): void {
    $rpcRequest = makeJsonRpcRequest('paid-echo-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeCallTool()->handle($rpcRequest, makeServerContext([new PaidEchoTool()]));

    $result = $response->toArray()['result'] ?? null;
    expect($result)->toBeArray();

    /** @var array<string, mixed> $resultArr */
    $resultArr = $result;
    expect($resultArr['isError'] ?? false)
        ->toBeFalse();

    $meta = $resultArr['_meta'] ?? null;
    expect($meta)->toBeArray();

    /** @var array<string, mixed> $metaArr */
    $metaArr = $meta;
    expect($metaArr['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('replays the cached response when the same payment is retried with the same scope (legitimate-retry path)', function (): void {
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $facilitator = new CountingFacilitator();
    $tool = makeCallTool(facilitator: $facilitator);

    $first = $tool->handle(
        makeJsonRpcRequest('paid-echo-tool', ['_meta' => ['x402/payment' => $payment]]),
        makeServerContext([new PaidEchoTool()]),
    );
    $second = $tool->handle(
        makeJsonRpcRequest('paid-echo-tool', ['_meta' => ['x402/payment' => $payment]]),
        makeServerContext([new PaidEchoTool()]),
    );

    /** @var array<string, mixed> $firstArr */
    $firstArr = $first->toArray()['result'];
    /** @var array<string, mixed> $secondArr */
    $secondArr = $second->toArray()['result'];

    // Both calls succeed, both carry identical bodies, including the same
    // settlement receipt. The user paid once; the cache replays the
    // delivered response on retry instead of letting `guardReplay` reject.
    expect($firstArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr)->toBe($firstArr);

    // Facilitator is hit exactly once across the two calls — the retry
    // is served entirely from the cache.
    expect($facilitator->verifyCalls)->toBe(1)
        ->and($facilitator->settleCalls)->toBe(1);
});

it('rejects replay against a different scope (security: same payment cannot satisfy a different tool)', function (): void {
    // Same signed payment, but the SECOND call targets a different tool
    // name. CacheScope segments include the method + tool URI, so the
    // second call MISSES the cache, falls through to `guardReplay`, and
    // gets rejected with `replay_attempt`. Pins the cross-scope
    // isolation invariant.
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $tool = makeCallTool();

    $first = $tool->handle(
        makeJsonRpcRequest('paid-echo-tool', ['_meta' => ['x402/payment' => $payment]]),
        makeServerContext([new PaidEchoTool()]),
    );
    $second = $tool->handle(
        makeJsonRpcRequest('other-paid-tool', ['_meta' => ['x402/payment' => $payment]]),
        makeServerContext([new PaidEchoTool(), new OtherPaidTool()]),
    );

    /** @var array<string, mixed> $firstArr */
    $firstArr = $first->toArray()['result'];
    /** @var array<string, mixed> $secondArr */
    $secondArr = $second->toArray()['result'];

    expect($firstArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $secondArr['structuredContent'];
    // Spec PaymentRequired body has only `error` (free-form). Canonical
    // reason is folded in via X402CallTool::paymentRequiredResult.
    expect($structured['error'] ?? '')->toContain('replay_attempt')
        ->and($structured)->not->toHaveKey('errorReason');
});

it('returns payment-required tool result when facilitator verify rejects', function (): void {
    $rpcRequest = makeJsonRpcRequest('paid-echo-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $facilitator = new StubFacilitator(verifyOk: false);
    $response = makeCallTool($facilitator)->handle($rpcRequest, makeServerContext([new PaidEchoTool()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['error'] ?? null)->toBe('rejected');
});

it('returns payment-required tool result when facilitator settle fails', function (): void {
    $rpcRequest = makeJsonRpcRequest('paid-echo-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $facilitator = new StubFacilitator(verifyOk: true, settleOk: false);
    $response = makeCallTool($facilitator)->handle($rpcRequest, makeServerContext([new PaidEchoTool()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['error'] ?? null)->toBe('failed');
});

it('delegates missing tool name to the parent CallTool which throws JsonRpcException', function (): void {
    // Free path: when params.name is missing, X402CallTool defers to
    // parent::handle, which throws -32602. Pin the parent contract so
    // an upstream change to that error code or shape forces an update.
    $rpcRequest = new JsonRpcRequest(
        id: 1,
        method: 'tools/call',
        params: ['arguments' => []],
    );

    expect(fn (): mixed => makeCallTool()->handle($rpcRequest, makeServerContext([new FreeEchoTool()])))
        ->toThrow(JsonRpcException::class, 'Missing [name] parameter.');
});

it('delegates unknown tool names to the parent CallTool which throws JsonRpcException', function (): void {
    // Free path: name is present but no Tool matches. Parent handles
    // this — our subclass returns null from resolveTool and falls
    // through to parent::handle, which throws -32602.
    $rpcRequest = makeJsonRpcRequest('does-not-exist');

    expect(fn (): mixed => makeCallTool()->handle($rpcRequest, makeServerContext([new FreeEchoTool()])))
        ->toThrow(JsonRpcException::class, 'Tool [does-not-exist] not found.');
});

it('pins the parent CallTool invocation contract for AuthorizationException', function (): void {
    // CONTRACT TEST. X402CallTool::runToolWithReceipt mirrors parent
    // CallTool::handle's Container::call + Auth/Validation catch (see
    // vendor/laravel/mcp/src/Server/Methods/CallTool.php:53-60). If
    // upstream ever changes that catch shape — drops a clause, swaps
    // exceptions, moves the dispatch elsewhere — paid tools would
    // silently lose the parity. This test pins the upstream behavior
    // by exercising the parent class directly. When this fails, audit
    // X402CallTool::runToolWithReceipt for the same change.
    //
    // laravel/mcp < 0.7 only caught ValidationException — the
    // AuthorizationException catch landed in 0.7. Under prefer-lowest
    // (0.6.x) the parent rethrows; the parity test below still
    // verifies *our* catch shape against that older parent.
    $filename = (new \ReflectionMethod(CallTool::class, 'handle'))->getFileName();
    $body = is_string($filename) ? file_get_contents($filename) : false;
    if (! is_string($body) || ! str_contains($body, 'AuthorizationException $authException')) {
        test()->markTestSkipped('parent CallTool::handle predates the AuthorizationException catch (laravel/mcp < 0.7)');
    }

    $parent = new CallTool();

    $rpcRequest = makeJsonRpcRequest('free-unauthorized-tool');
    $response = $parent->handle($rpcRequest, makeServerContext([new FreeUnauthorizedTool()]));

    expect($response)->not->toBeInstanceOf(Generator::class);

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var list<array<string, mixed>> $content */
    $content = $result['content'] ?? [];
    expect($content[0]['text'] ?? null)->toBe('not allowed by parent');
});

it('mirrors the parent invocation contract for paid tools and still attaches the receipt', function (): void {
    // PARITY TEST. Same scenario as the parent-contract test above,
    // but routed through X402CallTool with a priced tool. Asserts our
    // mirror in runToolWithReceipt produces the same wrapped error
    // result AND still injects the settlement receipt. If parent
    // changes its catch shape (see test above) and we update the
    // mirror to match, this should keep passing.
    $rpcRequest = makeJsonRpcRequest('paid-unauthorized-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeCallTool()->handle($rpcRequest, makeServerContext([new PaidUnauthorizedTool()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var list<array<string, mixed>> $content */
    $content = $result['content'] ?? [];
    expect($content[0]['text'] ?? null)->toBe('not allowed after settle');

    // Receipt MUST still attach — payment was settled before the
    // tool ran. This is intentional per the x402 v2 spec; the
    // README "Post-settle tool failure" section explains why.
    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'] ?? [];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('injects the receipt onto the terminal frame of a streaming response, not the notifications', function (): void {
    $rpcRequest = makeJsonRpcRequest('paid-streaming-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $frames = streamFrames(
        makeCallTool()->handle($rpcRequest, makeServerContext([new PaidStreamingTool()])),
    );

    expect($frames)->toHaveCount(2);

    // Frame 1 — JSON-RPC notification: NO `result` envelope, NO _meta receipt.
    $notif = $frames[0]->toArray();
    expect($notif['method'] ?? null)->toBe('progress')
        ->and($notif)->not->toHaveKey('result');

    /** @var array<string, mixed> $params */
    $params = $notif['params'];
    expect($params['_meta'] ?? null)->toBeNull();

    // Frame 2 — terminal data frame: receipt rides here.
    $terminal = $frames[1]->toArray();

    /** @var array<string, mixed> $result */
    $result = $terminal['result'];
    expect($result['isError'] ?? false)->toBeFalse();

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('still attaches the receipt to the closing frame when the tool yields only notifications', function (): void {
    $rpcRequest = makeJsonRpcRequest('paid-notifications-only-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $frames = streamFrames(
        makeCallTool()->handle($rpcRequest, makeServerContext([new PaidNotificationsOnlyTool()])),
    );

    // Two notifications + one terminal frame (empty pendingResponses).
    expect($frames)->toHaveCount(3);

    $terminal = $frames[2]->toArray();

    /** @var array<string, mixed> $result */
    $result = $terminal['result'];

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('stamps the receipt on the terminal frame even when the generator throws a generic Throwable mid-stream', function (): void {
    // Pins the README "Post-settle tool failure" guarantee: settled
    // payments always emit settlement proof, regardless of whether the
    // tool's mid-stream failure is `AuthorizationException`,
    // `ValidationException`, or a generic `RuntimeException`/`TypeError`/
    // `JsonException`. The wrapper in `X402CallTool::wrapStreamingForReceipt`
    // catches every Throwable except `JsonRpcException` (protocol-level,
    // must propagate) and yields a terminal `Response::error` so the
    // upstream's terminal `toJsonRpcResponse` runs `streamingSerializable`
    // and stamps `result._meta["x402/payment-response"]`.
    //
    // Inverted from the previous "receipt is lost in transit" test
    // following Codex review: for a paid system, observability of
    // payment-proof outweighs parity with the parent CallTool's narrower
    // catch shape.
    $rpcRequest = makeJsonRpcRequest('paid-streaming-throws-runtime-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $frames = streamFrames(
        makeCallTool()->handle($rpcRequest, makeServerContext([new PaidStreamingThrowsRuntimeTool()])),
    );

    expect($frames)->toHaveCount(2);

    // Frame 1: notification yielded before the throw, no result envelope.
    $notif = $frames[0]->toArray();
    expect($notif)->not->toHaveKey('result');

    // Frame 2: terminal error frame with receipt stamped.
    $terminal = $frames[1]->toArray();

    /** @var array<string, mixed> $result */
    $result = $terminal['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var list<array<string, mixed>> $content */
    $content = $result['content'] ?? [];
    expect($content[0]['text'] ?? null)->toBe('mid-stream generic failure');

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('attaches the receipt to the terminal error frame when the generator throws AuthorizationException mid-stream', function (): void {
    // Generator-time exceptions are caught inside vendor's
    // `Concerns/InteractsWithResponses::toJsonRpcStreamedResponse`, NOT
    // by `runToolWithReceipt`'s outer try/catch. The catch yields a
    // terminal `toJsonRpcResponse(Response::error(...), $serializable)`,
    // and our `streamingSerializable` runs there — so the receipt still
    // lands on the terminal error frame.
    $rpcRequest = makeJsonRpcRequest('paid-streaming-throws-tool', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $frames = streamFrames(
        makeCallTool()->handle($rpcRequest, makeServerContext([new PaidStreamingThrowsTool()])),
    );

    expect($frames)->toHaveCount(2);

    // Frame 1: the notification still emits before the throw.
    $notif = $frames[0]->toArray();
    expect($notif['method'] ?? null)->toBe('progress');

    // Frame 2: terminal error frame still carries the receipt.
    $terminal = $frames[1]->toArray();

    /** @var array<string, mixed> $result */
    $result = $terminal['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});
