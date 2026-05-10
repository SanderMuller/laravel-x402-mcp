<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
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
        config: resolve(Repository::class),
        responseCache: resolve(PaidToolResponseCache::class),
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
