<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\ServerContext;
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
use X402\Laravel\Mcp\Server\ChallengeFactory;
use X402\Laravel\Mcp\Server\Methods\X402GetPrompt;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Replay\InMemoryNonceStore;

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidOtherPrompt extends Prompt
{
    public function description(): string
    {
        return 'Second priced prompt — used for cross-prompt replay-rejection tests.';
    }

    public function handle(): Response
    {
        return Response::text('paid other prompt body');
    }
}

function makeGetPrompt(?FacilitatorClient $facilitator = null): X402GetPrompt
{
    return new X402GetPrompt(
        $facilitator ?? new StubFacilitator(),
        new InMemoryNonceStore(),
        responseCache: resolve(PaidToolResponseCache::class),
        challenges: resolve(ChallengeFactory::class),
    );
}

/**
 * @param  array<string, mixed>  $extraParams
 */
function makeGetPromptRequest(string $name, array $extraParams = []): JsonRpcRequest
{
    return new JsonRpcRequest(
        id: 1,
        method: 'prompts/get',
        params: array_merge(['name' => $name, 'arguments' => []], $extraParams),
    );
}

/**
 * @param  list<Prompt>  $prompts
 */
function makePromptContext(array $prompts): ServerContext
{
    return new ServerContext(
        supportedProtocolVersions: ['2025-11-25'],
        serverCapabilities: [],
        serverName: 'test',
        serverVersion: '0.0.1',
        instructions: '',
        maxPaginationLength: 50,
        defaultPaginationLength: 15,
        tools: [],
        resources: [],
        prompts: $prompts,
    );
}

// Errable marker is pinned by the Arch test in `tests/Arch/StructureTest.php`
// — runtime assertion would be a tautology since phpstan can see the
// `implements Errable` declaration.

it('returns a 402 challenge with isError + structuredContent when no payment meta is present', function (): void {
    $rpcRequest = makeGetPromptRequest('paid-echo-prompt');

    $response = makeGetPrompt()->handle($rpcRequest, makePromptContext([new PaidEchoPrompt()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['x402Version'] ?? null)->toBe(2)
        ->and($structured['error'] ?? null)->toBe('Payment required.')
        ->and($structured['accepts'] ?? null)->toHaveCount(1);

    // Prompts synthesise `mcp://prompt/{name}` for the challenge resource.
    /** @var array<string, mixed> $resourceInfo */
    $resourceInfo = $structured['resource'];
    expect($resourceInfo['url'] ?? null)->toBe('mcp://prompt/paid-echo-prompt');
});

it('passes free prompts through to the parent GetPrompt without consulting the facilitator', function (): void {
    $facilitator = new class implements FacilitatorClient {
        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            throw new RuntimeException('facilitator must not be called for free prompts');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            throw new RuntimeException('facilitator must not be called for free prompts');
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

    $response = makeGetPrompt($facilitator)->handle(
        makeGetPromptRequest('free-echo-prompt'),
        makePromptContext([new FreeEchoPrompt()]),
    );

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? false)->toBeFalse();
});

it('settles and injects x402/payment-response into result._meta on a valid signature', function (): void {
    $rpcRequest = makeGetPromptRequest('paid-echo-prompt', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeGetPrompt()->handle($rpcRequest, makePromptContext([new PaidEchoPrompt()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? false)->toBeFalse();

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('replays the cached response when the same payment is retried with the same prompt + arguments (legitimate-retry path)', function (): void {
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $handler = makeGetPrompt();

    $first = $handler->handle(
        makeGetPromptRequest('paid-echo-prompt', ['_meta' => ['x402/payment' => $payment]]),
        makePromptContext([new PaidEchoPrompt()]),
    );
    $second = $handler->handle(
        makeGetPromptRequest('paid-echo-prompt', ['_meta' => ['x402/payment' => $payment]]),
        makePromptContext([new PaidEchoPrompt()]),
    );

    /** @var array<string, mixed> $firstArr */
    $firstArr = $first->toArray()['result'];
    /** @var array<string, mixed> $secondArr */
    $secondArr = $second->toArray()['result'];

    expect($firstArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr)->toBe($firstArr);
});

it('rejects replay against a different prompt name (security: same payment cannot satisfy a different prompt)', function (): void {
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $handler = makeGetPrompt();

    $first = $handler->handle(
        makeGetPromptRequest('paid-echo-prompt', ['_meta' => ['x402/payment' => $payment]]),
        makePromptContext([new PaidEchoPrompt()]),
    );

    // Different prompt name → different CacheScope → cache MISS →
    // guardReplay rejects with `replay_attempt`. Pins the cross-prompt
    // isolation invariant.
    $second = $handler->handle(
        makeGetPromptRequest('paid-other-prompt', ['_meta' => ['x402/payment' => $payment]]),
        makePromptContext([new PaidEchoPrompt(), new PaidOtherPrompt()]),
    );

    /** @var array<string, mixed> $firstArr */
    $firstArr = $first->toArray()['result'];
    /** @var array<string, mixed> $secondArr */
    $secondArr = $second->toArray()['result'];

    expect($firstArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $secondArr['structuredContent'];
    expect($structured['error'] ?? '')->toContain('replay_attempt');
});

it('returns payment-required when the facilitator verify rejects', function (): void {
    $rpcRequest = makeGetPromptRequest('paid-echo-prompt', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeGetPrompt(new StubFacilitator(verifyOk: false))
        ->handle($rpcRequest, makePromptContext([new PaidEchoPrompt()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['error'] ?? null)->toBe('rejected');
});

it('returns payment-required when the facilitator settle fails', function (): void {
    $rpcRequest = makeGetPromptRequest('paid-echo-prompt', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeGetPrompt(new StubFacilitator(verifyOk: true, settleOk: false))
        ->handle($rpcRequest, makePromptContext([new PaidEchoPrompt()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['error'] ?? null)->toBe('failed');
});

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingThrowsRuntimePrompt extends Prompt
{
    public function description(): string
    {
        return 'Paid streaming prompt that yields one notification then throws RuntimeException — pins the asymmetry vs X402CallTool.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(): Generator
    {
        yield Response::notification('progress', ['percent' => 50]);

        throw new RuntimeException('mid-stream generic prompt failure');
    }
}

it('does NOT wrap mid-stream Throwables (asymmetry vs X402CallTool) — generic exceptions propagate past the receipt', function (): void {
    // Pinned by Codex review (§2.6): X402GetPrompt mirrors X402ReadResource
    // here, NOT X402CallTool. Vendor toJsonRpcStreamedResponse catches
    // Auth/Authn/Validation only — generic Throwables propagate. This
    // test pins the intentional regression surface so a future
    // "consistency pass" doesn't silently extend wrapStreamingForReceipt
    // to all three handlers.
    $rpcRequest = makeGetPromptRequest('paid-streaming-throws-runtime-prompt', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $generator = makeGetPrompt()->handle(
        $rpcRequest,
        makePromptContext([new PaidStreamingThrowsRuntimePrompt()]),
    );

    expect($generator)->toBeInstanceOf(Generator::class);

    $threw = false;
    try {
        /** @var Generator<int, mixed> $generator */
        iterator_to_array($generator, preserve_keys: false);
    } catch (RuntimeException $runtimeException) {
        $threw = true;
        expect($runtimeException->getMessage())->toBe('mid-stream generic prompt failure');
    }

    expect($threw)->toBeTrue('Expected the generic mid-stream Throwable to propagate past the receipt.');
});

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingThrowsAuthPrompt extends Prompt
{
    public function description(): string
    {
        return 'Paid streaming prompt that yields one notification then throws AuthorizationException — exercises the vendor-caught path.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(): Generator
    {
        yield Response::notification('progress', ['percent' => 50]);

        throw new AuthorizationException('mid-stream auth fail');
    }
}

it('stamps the receipt on the terminal error frame for vendor-caught mid-stream AuthorizationException (pins shared streamingSerializable contract)', function (): void {
    // Codex-flagged regression surface: the refactor moved receipt
    // stamping into PaymentGate::streamingSerializable. If the closure
    // capture or self::META_RESPONSE_KEY constant lookup drifted, receipt
    // delivery would silently break for the Auth/Authn/Validation path
    // that vendor toJsonRpcStreamedResponse catches and re-emits as a
    // terminal error frame. Pin the wire shape here so a future trait
    // edit can't break it without a test failure.
    $rpcRequest = makeGetPromptRequest('paid-streaming-throws-auth-prompt', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $generator = makeGetPrompt()->handle(
        $rpcRequest,
        makePromptContext([new PaidStreamingThrowsAuthPrompt()]),
    );

    expect($generator)->toBeInstanceOf(Generator::class);

    /** @var Generator<int, JsonRpcResponse> $generator */
    $frames = iterator_to_array($generator, preserve_keys: false);

    // Two frames: progress notification + terminal frame.
    expect($frames)->toHaveCount(2);

    // Terminal frame: the receipt MUST be present in `_meta`. Vendor
    // `GetPrompt::serializable` produces `{description, messages}` without
    // an `isError` key, so we assert the receipt itself rather than
    // envelope-shape fields the parent serializer never emits.
    $terminal = $frames[1]->toArray();

    /** @var array<string, mixed> $result */
    $result = $terminal['result'];

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});
