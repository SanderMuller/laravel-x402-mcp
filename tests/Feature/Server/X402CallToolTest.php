<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Facilitator\DiscoveryPage;
use X402\Facilitator\DiscoveryQuery;
use X402\Facilitator\FacilitatorClient;
use X402\Facilitator\SettleResult;
use X402\Facilitator\SupportedKinds;
use X402\Facilitator\VerifyResult;
use X402\Laravel\Mcp\Attributes\X402Price;
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

final readonly class StubFacilitator implements FacilitatorClient
{
    public function __construct(
        public bool $verifyOk = true,
        public bool $settleOk = true,
    ) {}

    public function verify(PaymentSignature $signature, PaymentRequired $challenge): VerifyResult
    {
        return new VerifyResult($this->verifyOk, $this->verifyOk ? null : 'rejected', '0xpayer');
    }

    public function settle(PaymentSignature $signature, PaymentRequired $challenge): SettleResult
    {
        return new SettleResult($this->settleOk, $this->settleOk ? '0xtxhash' : '', $challenge->network, '0xpayer', $this->settleOk ? null : 'failed');
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

function makeCallTool(?FacilitatorClient $facilitator = null, ?NonceStoreContract $store = null): X402CallTool
{
    return new X402CallTool(
        $facilitator ?? new StubFacilitator(),
        $store ?? new InMemoryNonceStore(),
        config: resolve(Repository::class),
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

/**
 * @return array<string, mixed>
 */
function buildPaymentMeta(string $payTo): array
{
    return [
        'x402Version' => 2,
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => $payTo,
                'value' => '10000',
                'validAfter' => Date::now()
                    ->getTimestamp() - 10,
                'validBefore' => Date::now()
                    ->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ];
}

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
    expect($metaArr['x402/payment-response'] ?? null)->toBe([
        'success' => true,
        'transaction' => '0xtxhash',
        'network' => 'eip155:8453',
        'payer' => '0xpayer',
    ]);
});

it('rejects nonce reuse with a payment-required tool result', function (): void {
    $payment = buildPaymentMeta('0x000000000000000000000000000000000000beef');
    $store = new InMemoryNonceStore();
    $tool = makeCallTool(store: $store);

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

    expect($firstArr['isError'] ?? false)
        ->toBeFalse()
        ->and($secondArr['isError'] ?? null)
        ->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $secondArr['structuredContent'];
    // Spec PaymentRequired body has only `error` (free-form). Canonical
    // reason is folded in via X402CallTool::paymentRequiredResult.
    expect($structured['error'] ?? '')->toContain('replay_attempt');
    expect($structured)->not->toHaveKey('errorReason');
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
