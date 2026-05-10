<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Support\UriTemplate;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
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
        config: resolve(Repository::class),
        responseCache: resolve(PaidToolResponseCache::class),
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
