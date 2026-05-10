<?php

declare(strict_types=1);

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Server\Methods\X402ListPrompts;

/**
 * @param  list<Prompt>  $prompts
 */
function makePromptListContext(array $prompts): ServerContext
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

it('advertises x402/price under _meta for priced prompts', function (): void {
    // PaidEchoPrompt is defined in X402GetPromptTest.php; reuse its
    // class fixture by name (Pest loads fixtures globally).
    $context = makePromptListContext([new PaidEchoPrompt()]);
    $request = new JsonRpcRequest(id: 1, method: 'prompts/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListPrompts())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $prompts */
    $prompts = $result['prompts'];

    expect($prompts)->toHaveCount(1);

    $meta = $prompts[0]['_meta'] ?? null;
    expect($meta)->toBeArray();

    /** @var array<string, mixed> $metaArr */
    $metaArr = $meta;
    expect($metaArr['x402/price'] ?? null)->toBe([
        'amount' => '0.01',
        'asset' => 'USDC',
        'network' => 'base',
    ]);
});

it('does not attach _meta x402/price to free prompts', function (): void {
    $context = makePromptListContext([new FreeEchoPrompt()]);
    $request = new JsonRpcRequest(id: 1, method: 'prompts/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListPrompts())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $prompts */
    $prompts = $result['prompts'];

    expect($prompts)->toHaveCount(1);

    $meta = $prompts[0]['_meta'] ?? null;
    if (is_array($meta)) {
        expect($meta)->not->toHaveKey('x402/price');
    } else {
        expect($meta)->toBeNull();
    }
});

it('includes payTo on prompt price advertisement when overridden', function (): void {
    $prompt = new #[X402Price(amount: '5.00', asset: 'USDC', network: 'base', payTo: '0x000000000000000000000000000000000000beef', )] class extends Prompt {
        public function description(): string
        {
            return 'priced with payTo override';
        }

        public function handle(): Response
        {
            return Response::text('priced with payTo override body');
        }
    };

    $context = makePromptListContext([$prompt]);
    $request = new JsonRpcRequest(id: 1, method: 'prompts/list', params: []);

    /** @var array<string, mixed> $result */
    $result = (new X402ListPrompts())->handle($request, $context)->toArray()['result'];

    /** @var list<array<string, mixed>> $prompts */
    $prompts = $result['prompts'];

    /** @var array<string, mixed> $meta */
    $meta = $prompts[0]['_meta'];

    expect($meta['x402/price'] ?? null)->toBe([
        'amount' => '5.00',
        'asset' => 'USDC',
        'network' => 'base',
        'payTo' => '0x000000000000000000000000000000000000beef',
    ]);
});
