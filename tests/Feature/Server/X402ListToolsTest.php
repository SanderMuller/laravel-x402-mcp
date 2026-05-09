<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Methods\X402ListTools;

/**
 * @param  list<Tool>  $tools
 */
function makeListContext(array $tools): ServerContext
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

it('advertises x402/price under _meta for priced tools', function (): void {
    $context = makeListContext([new PaidEchoTool()]);
    $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListTools())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $tools */
    $tools = $result['tools'];

    expect($tools)->toHaveCount(1);

    $meta = $tools[0]['_meta'] ?? null;
    expect($meta)->toBeArray();

    /** @var array<string, mixed> $metaArr */
    $metaArr = $meta;
    expect($metaArr['x402/price'] ?? null)->toBe([
        'amount' => '0.01',
        'asset' => 'USDC',
        'network' => 'base',
    ]);
});

it('does not attach _meta x402/price to free tools', function (): void {
    $context = makeListContext([new FreeEchoTool()]);
    $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListTools())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $tools */
    $tools = $result['tools'];

    expect($tools)->toHaveCount(1);

    $meta = $tools[0]['_meta'] ?? null;
    if (is_array($meta)) {
        expect($meta)->not->toHaveKey('x402/price');
    } else {
        expect($meta)->toBeNull();
    }
});

it('includes payTo in advertised price when the attribute overrides it', function (): void {
    $tool = new #[X402Price(amount: '5.00', asset: 'USDC', network: 'base', payTo: '0x000000000000000000000000000000000000beef', )] class extends Tool {
        public function description(): string
        {
            return 'priced with payTo override';
        }

        public function handle(Request $request): Response
        {
            return Response::json(['ok' => true]);
        }
    };

    $context = makeListContext([$tool]);
    $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListTools())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $tools */
    $tools = $result['tools'];

    /** @var array<string, mixed> $meta */
    $meta = $tools[0]['_meta'];

    expect($meta['x402/price'] ?? null)->toBe([
        'amount' => '5.00',
        'asset' => 'USDC',
        'network' => 'base',
        'payTo' => '0x000000000000000000000000000000000000beef',
    ]);
});
