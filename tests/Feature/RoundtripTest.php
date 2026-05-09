<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use X402\Laravel\Facades\X402;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Concerns\WithX402Payment;

#[X402Price(amount: '0.01', asset: 'USDC', network: 'base')]
final class RoundtripPaidTool extends Tool
{
    public function description(): string
    {
        return 'paid roundtrip tool';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['echo' => 'paid']);
    }
}

final class RoundtripServer extends Server
{
    use WithX402Payment;

    /** @var array<int, class-string<Tool>|Tool> */
    protected array $tools = [
        RoundtripPaidTool::class,
    ];
}

beforeEach(function (): void {
    Mcp::web('/mcp-roundtrip', RoundtripServer::class);
});

/**
 * @return array<string, mixed>
 */
function roundtripPaymentMeta(): array
{
    return [
        'x402Version' => 2,
        'scheme' => 'exact',
        'network' => 'eip155:8453',
        'payload' => [
            'signature' => '0xdeadbeef',
            'authorization' => [
                'from' => '0xfrom',
                'to' => '0x000000000000000000000000000000000000beef',
                'value' => '10000',
                'validAfter' => Date::now()->getTimestamp() - 10,
                'validBefore' => Date::now()->getTimestamp() + 60,
                'nonce' => '0x' . bin2hex(random_bytes(32)),
            ],
        ],
    ];
}

it('serves a 402 challenge over the HTTP transport when no payment is presented', function (): void {
    $fake = X402::fake();

    $response = $this->postJson('/mcp-roundtrip', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'roundtrip-paid-tool',
            'arguments' => (object) [],
        ],
    ]);

    $response->assertOk();

    /** @var array<string, mixed> $body */
    $body = $response->json();

    /** @var array<string, mixed> $result */
    $result = $body['result'];

    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];

    expect($structured['x402Version'] ?? null)->toBe(2)
        ->and($structured['error'] ?? null)->toBe('Payment required.')
        ->and($structured['accepts'] ?? null)->toHaveCount(1);

    /** @var array<int, array<string, mixed>> $accepts */
    $accepts = $structured['accepts'];

    expect($accepts[0]['scheme'] ?? null)->toBe('exact')
        ->and($accepts[0]['network'] ?? null)->toBe('eip155:8453')
        ->and($accepts[0]['payTo'] ?? null)->toBe('0x000000000000000000000000000000000000beef');

    /** @var array<string, mixed> $resource */
    $resource = $structured['resource'];

    expect($resource['url'] ?? null)->toBe('mcp://tool/roundtrip-paid-tool')
        ->and($fake->verifyCalls())
        ->toBeEmpty()
        ->and($fake->settleCalls())
        ->toBeEmpty();
});

it('settles via the bound facilitator and returns the receipt under result._meta', function (): void {
    $fake = X402::fake();

    $response = $this->postJson('/mcp-roundtrip', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'roundtrip-paid-tool',
            'arguments' => (object) [],
            '_meta' => [
                'x402/payment' => roundtripPaymentMeta(),
            ],
        ],
    ]);

    $response->assertOk();

    /** @var array<string, mixed> $body */
    $body = $response->json();

    /** @var array<string, mixed> $result */
    $result = $body['result'];

    expect($result['isError'] ?? false)->toBeFalse();

    /** @var array<string, mixed> $meta */
    $meta = $result['_meta'];

    expect($meta['x402/payment-response'] ?? null)->toBe([
        'success' => true,
        'transaction' => '0xtxhash',
        'network' => 'eip155:8453',
        'payer' => '0xpayer',
    ]);

    $fake->assertVerified('mcp://tool/roundtrip-paid-tool');
    $fake->assertSettled('mcp://tool/roundtrip-paid-tool');
});

it('does not call settle when the facilitator rejects verify', function (): void {
    $fake = X402::fake()->rejectVerify('insufficient-funds');

    $response = $this->postJson('/mcp-roundtrip', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => [
            'name' => 'roundtrip-paid-tool',
            'arguments' => (object) [],
            '_meta' => [
                'x402/payment' => roundtripPaymentMeta(),
            ],
        ],
    ]);

    $response->assertOk();

    /** @var array<string, mixed> $body */
    $body = $response->json();

    /** @var array<string, mixed> $result */
    $result = $body['result'];

    expect($result['isError'] ?? null)->toBeTrue();

    /** @var array<string, mixed> $structured */
    $structured = $result['structuredContent'];

    expect($structured['error'] ?? null)->toBe('insufficient-funds');

    $fake->assertNothingSettled();
});

it('advertises x402/price under _meta on tools/list responses', function (): void {
    $response = $this->postJson('/mcp-roundtrip', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/list',
        'params' => (object) [],
    ]);

    $response->assertOk();

    /** @var array<string, mixed> $body */
    $body = $response->json();

    /** @var array<string, mixed> $result */
    $result = $body['result'];

    /** @var array<int, array<string, mixed>> $tools */
    $tools = $result['tools'];

    expect($tools)->toHaveCount(1)
        ->and($tools[0]['name'] ?? null)->toBe('roundtrip-paid-tool');

    /** @var array<string, mixed> $meta */
    $meta = $tools[0]['_meta'];

    expect($meta['x402/price'] ?? null)->toBe([
        'amount' => '0.01',
        'asset' => 'USDC',
        'network' => 'base',
    ]);
});
