<?php

declare(strict_types=1);

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Methods\X402ListResources;

/**
 * @param list<resource> $resources
 */
function makeResourceListContext(array $resources): ServerContext
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

it('advertises x402/price under _meta for priced resources', function (): void {
    // PaidEchoResource is defined in X402ReadResourceTest.php; reuse its
    // class fixture by name (Pest loads fixtures globally).
    $context = makeResourceListContext([new PaidEchoResource()]);
    $request = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListResources())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $resources */
    $resources = $result['resources'];

    expect($resources)->toHaveCount(1);

    $meta = $resources[0]['_meta'] ?? null;
    expect($meta)->toBeArray();

    /** @var array<string, mixed> $metaArr */
    $metaArr = $meta;
    expect($metaArr['x402/price'] ?? null)->toBe([
        'amount' => '0.01',
        'asset' => 'USDC',
        'network' => 'base',
    ]);
});

it('does not attach _meta x402/price to free resources', function (): void {
    $context = makeResourceListContext([new FreeEchoResource()]);
    $request = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListResources())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $resources */
    $resources = $result['resources'];

    expect($resources)->toHaveCount(1);

    $meta = $resources[0]['_meta'] ?? null;
    if (is_array($meta)) {
        expect($meta)->not->toHaveKey('x402/price');
    } else {
        expect($meta)->toBeNull();
    }
});

it('includes payTo on resource price advertisement when overridden', function (): void {
    $resource = new #[X402Price(amount: '5.00', asset: 'USDC', network: 'base', payTo: '0x000000000000000000000000000000000000beef', )] class extends Resource {
        protected string $uri = 'mcp://test/paid-payto-override';

        public function description(): string
        {
            return 'priced with payTo override';
        }

        public function handle(): Response
        {
            return Response::text('priced with payTo override body');
        }
    };

    $context = makeResourceListContext([$resource]);
    $request = new JsonRpcRequest(id: 1, method: 'resources/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListResources())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $resources */
    $resources = $result['resources'];

    /** @var array<string, mixed> $meta */
    $meta = $resources[0]['_meta'];

    expect($meta['x402/price'] ?? null)->toBe([
        'amount' => '5.00',
        'asset' => 'USDC',
        'network' => 'base',
        'payTo' => '0x000000000000000000000000000000000000beef',
    ]);
});
