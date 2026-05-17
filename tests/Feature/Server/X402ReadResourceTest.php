<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Laravel\Mcp\Support\UriTemplate;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Laravel\Mcp\Server\ChallengeFactory;
use X402\Laravel\Mcp\Server\Methods\X402ReadResource;
use X402\Protocol\PaymentRequired;
use X402\Protocol\PaymentSignature;
use X402\Replay\InMemoryNonceStore;

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidEchoResource2 extends Resource
{
    protected string $uri = 'mcp://test/paid-2';

    public function description(): string
    {
        return 'Second priced resource — used for cross-URI replay-rejection tests.';
    }

    public function handle(): Response
    {
        return Response::text('paid resource body 2');
    }
}

#[X402Price(amount: '0.05', asset: 'USDC', network: 'base')]
final class PaidTemplatedResource extends Resource implements HasUriTemplate
{
    public function description(): string
    {
        return 'Paid templated resource — pins HasUriTemplate variable binding under x402 gating.';
    }

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('mcp://test/paid-{id}');
    }

    public function handle(McpRequest $request): Response
    {
        // The variable bound from `parent::invokeResource` must be readable
        // here — proves we did NOT bypass invokeResource into a bare
        // Container::call.
        $idRaw = $request->get('id');
        $id = is_scalar($idRaw) ? (string) $idRaw : '';

        return Response::text("paid templated body for id={$id}");
    }
}

function makeReadResource(?FacilitatorClient $facilitator = null): X402ReadResource
{
    return new X402ReadResource(
        $facilitator ?? new StubFacilitator(),
        new InMemoryNonceStore(),
        responseCache: resolve(PaidToolResponseCache::class),
        challenges: resolve(ChallengeFactory::class),
    );
}

/**
 * @param  array<string, mixed>  $extraParams
 */
function makeReadResourceRequest(string $uri, array $extraParams = []): JsonRpcRequest
{
    return new JsonRpcRequest(
        id: 1,
        method: 'resources/read',
        params: array_merge(['uri' => $uri], $extraParams),
    );
}

/**
 * @param list<resource> $resources
 */
function makeResourceContext(array $resources): ServerContext
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
        resources: $resources,
        prompts: [],
    );
}

// Errable marker is pinned by the Arch test in `tests/Arch/StructureTest.php`
// — runtime assertion would be a tautology since phpstan can see the
// `implements Errable` declaration.

it('returns a 402 challenge with isError + structuredContent when no payment meta is present', function (): void {
    $rpcRequest = makeReadResourceRequest('mcp://test/paid-echo');

    $response = makeReadResource()->handle($rpcRequest, makeResourceContext([new PaidEchoResource()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['x402Version'] ?? null)->toBe(2)
        ->and($structured['error'] ?? null)->toBe('Payment required.')
        ->and($structured['accepts'] ?? null)->toHaveCount(1);

    /** @var array<int, array<string, mixed>> $accepts */
    $accepts = $structured['accepts'];
    expect($accepts[0]['network'] ?? null)->toBe('eip155:8453');

    // Resources put the URI verbatim under top-level `resource.url`.
    /** @var array<string, mixed> $resourceInfo */
    $resourceInfo = $structured['resource'];
    expect($resourceInfo['url'] ?? null)->toBe('mcp://test/paid-echo');
});

it('passes free resources through to the parent ReadResource without consulting the facilitator', function (): void {
    $facilitator = new class implements FacilitatorClient {
        public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
        {
            throw new RuntimeException('facilitator must not be called for free resources');
        }

        public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
        {
            throw new RuntimeException('facilitator must not be called for free resources');
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

    $response = makeReadResource($facilitator)->handle(
        makeReadResourceRequest('mcp://test/free-echo'),
        makeResourceContext([new FreeEchoResource()]),
    );

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? false)->toBeFalse();
});

it('settles and injects x402/payment-response into result._meta on a valid signature', function (): void {
    $rpcRequest = makeReadResourceRequest('mcp://test/paid-echo', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeReadResource()->handle($rpcRequest, makeResourceContext([new PaidEchoResource()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? false)->toBeFalse();

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});

it('replays the cached response when the same payment is retried with the same URI (legitimate-retry path)', function (): void {
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $handler = makeReadResource();

    $first = $handler->handle(
        makeReadResourceRequest('mcp://test/paid-echo', ['_meta' => ['x402/payment' => $payment]]),
        makeResourceContext([new PaidEchoResource()]),
    );
    $second = $handler->handle(
        makeReadResourceRequest('mcp://test/paid-echo', ['_meta' => ['x402/payment' => $payment]]),
        makeResourceContext([new PaidEchoResource()]),
    );

    /** @var array<string, mixed> $firstArr */
    $firstArr = $first->toArray()['result'];
    /** @var array<string, mixed> $secondArr */
    $secondArr = $second->toArray()['result'];

    expect($firstArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr['isError'] ?? false)->toBeFalse()
        ->and($secondArr)->toBe($firstArr);
});

it('rejects replay against a different concrete URI (security: same payment cannot satisfy a different resource)', function (): void {
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $handler = makeReadResource();

    $first = $handler->handle(
        makeReadResourceRequest('mcp://test/paid-echo', ['_meta' => ['x402/payment' => $payment]]),
        makeResourceContext([new PaidEchoResource()]),
    );

    // Concrete URI matches a different priced resource — different
    // CacheScope segments → cache MISS → guardReplay rejects with
    // `replay_attempt`. Pins the cross-URI isolation invariant.
    $second = $handler->handle(
        makeReadResourceRequest('mcp://test/paid-2', ['_meta' => ['x402/payment' => $payment]]),
        makeResourceContext([new PaidEchoResource(), new PaidEchoResource2()]),
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
    $rpcRequest = makeReadResourceRequest('mcp://test/paid-echo', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeReadResource(new StubFacilitator(verifyOk: false))
        ->handle($rpcRequest, makeResourceContext([new PaidEchoResource()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['error'] ?? null)->toBe('rejected');
});

it('returns payment-required when the facilitator settle fails', function (): void {
    $rpcRequest = makeReadResourceRequest('mcp://test/paid-echo', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeReadResource(new StubFacilitator(verifyOk: true, settleOk: false))
        ->handle($rpcRequest, makeResourceContext([new PaidEchoResource()]));

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];
    expect($structured['error'] ?? null)->toBe('failed');
});

it('preserves HasUriTemplate variable binding under x402 gating — uses parent::invokeResource not a bare Container::call', function (): void {
    // Concrete URI matching the template `mcp://test/paid-{id}`. The
    // resource handle reads `$request->get('id')`; if invokeResource is
    // skipped, the variable is never merged into the request and the
    // body would not contain the id.
    $concreteUri = 'mcp://test/paid-42';
    $rpcRequest = makeReadResourceRequest($concreteUri, [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $response = makeReadResource()->handle(
        $rpcRequest,
        makeResourceContext([new PaidTemplatedResource()]),
    );

    /** @var array<string, mixed> $result */
    $result = $response->toArray()['result'];
    expect($result['isError'] ?? false)->toBeFalse();

    // Receipt still rides the result.
    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());

    // Body proves the id was bound from the URI template.
    /** @var list<array<string, mixed>> $contents */
    $contents = $result['contents'];
    expect($contents[0]['text'] ?? null)->toBe('paid templated body for id=42')
        ->and($contents[0]['uri'] ?? null)->toBe($concreteUri);
});

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingThrowsRuntimeResource extends Resource
{
    protected string $uri = 'mcp://test/paid-streaming-throws-runtime-resource';

    public function description(): string
    {
        return 'Paid streaming resource that yields one notification then throws RuntimeException — pins the asymmetry vs X402CallTool.';
    }

    /**
     * @return Generator<int, Response>
     */
    public function handle(): Generator
    {
        yield Response::notification('progress', ['percent' => 50]);

        throw new RuntimeException('mid-stream generic resource failure');
    }
}

it('does NOT wrap mid-stream Throwables (asymmetry vs X402CallTool) — generic exceptions propagate past the receipt', function (): void {
    // Pinned by Codex review (§2.6): X402CallTool wraps via
    // wrapStreamingForReceipt and stamps the receipt on a terminal error
    // frame for any Throwable. X402ReadResource intentionally does NOT
    // wrap — vendor toJsonRpcStreamedResponse only catches Auth/Authn/
    // Validation, so generic Throwables propagate. This test pins the
    // intentional regression surface so a future "consistency pass"
    // doesn't silently extend the wrapper to all three handlers.
    $rpcRequest = makeReadResourceRequest('mcp://test/paid-streaming-throws-runtime-resource', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $generator = makeReadResource()->handle(
        $rpcRequest,
        makeResourceContext([new PaidStreamingThrowsRuntimeResource()]),
    );

    expect($generator)->toBeInstanceOf(Generator::class);

    $threw = false;
    try {
        /** @var Generator<int, mixed> $generator */
        iterator_to_array($generator, preserve_keys: false);
    } catch (RuntimeException $runtimeException) {
        $threw = true;
        expect($runtimeException->getMessage())->toBe('mid-stream generic resource failure');
    }

    expect($threw)->toBeTrue('Expected the generic mid-stream Throwable to propagate past the receipt.');
});

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class PaidStreamingThrowsAuthResource extends Resource
{
    protected string $uri = 'mcp://test/paid-streaming-throws-auth-resource';

    public function description(): string
    {
        return 'Paid streaming resource that yields one notification then throws AuthorizationException — exercises the vendor-caught path.';
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
    // capture or self::META_RESPONSE_KEY constant lookup drifted,
    // receipt delivery would silently break for the Auth/Authn/Validation
    // path that vendor toJsonRpcStreamedResponse catches and re-emits as
    // a terminal error frame. Pin the wire shape here so a future trait
    // edit can't break it without a test failure.
    $rpcRequest = makeReadResourceRequest('mcp://test/paid-streaming-throws-auth-resource', [
        '_meta' => ['x402/payment' => buildPaymentMeta('0x000000000000000000000000000000000000beef')],
    ]);

    $generator = makeReadResource()->handle(
        $rpcRequest,
        makeResourceContext([new PaidStreamingThrowsAuthResource()]),
    );

    expect($generator)->toBeInstanceOf(Generator::class);

    /** @var Generator<int, JsonRpcResponse> $generator */
    $frames = iterator_to_array($generator, preserve_keys: false);

    // Two frames: progress notification + terminal frame.
    expect($frames)->toHaveCount(2);

    // Terminal frame: the receipt MUST be present in `_meta`. Vendor
    // `ReadResource::serializable` produces `{contents: [...]}` without
    // an `isError` key, so we assert the receipt itself rather than
    // envelope-shape fields the parent serializer never emits.
    $terminal = $frames[1]->toArray();

    /** @var array<string, mixed> $result */
    $result = $terminal['result'];

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];
    expect($meta['x402/payment-response'] ?? null)->toBe(expectedReceipt());
});
