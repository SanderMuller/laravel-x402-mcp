<?php

declare(strict_types=1);

use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Laravel\Mcp\Server\ChallengeFactory;
use X402\Laravel\Mcp\Server\Methods\X402CallTool;
use X402\Laravel\Mcp\Server\Methods\X402GetPrompt;
use X402\Laravel\Mcp\Server\Methods\X402ReadResource;
use X402\Replay\InMemoryNonceStore;

/**
 * Locks the cross-handler invariant the comments currently assert
 * prose-only: a 402 challenge MUST emit identical envelope shape
 * (`isError`, `structuredContent`, `content[0].text`) regardless of
 * which primitive (Tool / Resource / Prompt) was invoked.
 *
 * Until this refactor the shape was guaranteed by three duplicated
 * `paymentRequiredResult` methods. After the trait collapse, only one
 * method emits the envelope — but a future change to the trait could
 * silently drift one handler off-spec without this test.
 */
/**
 * @param  array<string, mixed>  $result
 * @return list<string>
 */
function envelopeKeys(array $result): array
{
    $keys = array_keys($result);
    sort($keys);

    return $keys;
}

it('emits identical 402 envelope shape across X402CallTool, X402ReadResource, and X402GetPrompt', function (): void {
    $context = new ServerContext(
        supportedProtocolVersions: ['2025-11-25'],
        serverCapabilities: [],
        serverName: 'test',
        serverVersion: '0.0.1',
        instructions: '',
        maxPaginationLength: 50,
        defaultPaginationLength: 15,
        tools: [new PaidEchoTool()],
        resources: [new PaidEchoResource()],
        prompts: [new PaidEchoPrompt()],
    );

    $tool = new X402CallTool(
        new StubFacilitator(),
        new InMemoryNonceStore(),
        responseCache: resolve(PaidToolResponseCache::class),
        challenges: resolve(ChallengeFactory::class),
    );
    $resource = new X402ReadResource(
        new StubFacilitator(),
        new InMemoryNonceStore(),
        responseCache: resolve(PaidToolResponseCache::class),
        challenges: resolve(ChallengeFactory::class),
    );
    $prompt = new X402GetPrompt(
        new StubFacilitator(),
        new InMemoryNonceStore(),
        responseCache: resolve(PaidToolResponseCache::class),
        challenges: resolve(ChallengeFactory::class),
    );

    $toolResp = $tool->handle(
        new JsonRpcRequest(id: 1, method: 'tools/call', params: ['name' => 'paid-echo-tool', 'arguments' => []]),
        $context,
    );
    $resourceResp = $resource->handle(
        new JsonRpcRequest(id: 1, method: 'resources/read', params: ['uri' => 'mcp://test/paid-echo']),
        $context,
    );
    $promptResp = $prompt->handle(
        new JsonRpcRequest(id: 1, method: 'prompts/get', params: ['name' => 'paid-echo-prompt', 'arguments' => []]),
        $context,
    );

    /** @var array<string, mixed> $toolResult */
    $toolResult = $toolResp->toArray()['result'];
    /** @var array<string, mixed> $resourceResult */
    $resourceResult = $resourceResp->toArray()['result'];
    /** @var array<string, mixed> $promptResult */
    $promptResult = $promptResp->toArray()['result'];

    // Identical key set across all three. No `_meta` — `paymentRequiredResult`
    // does not stamp a payment receipt onto a 402 challenge.
    $expectedKeys = ['content', 'isError', 'structuredContent'];
    expect(envelopeKeys($toolResult))->toBe($expectedKeys)
        ->and(envelopeKeys($resourceResult))->toBe($expectedKeys)
        ->and(envelopeKeys($promptResult))->toBe($expectedKeys);

    // Identical isError flag.
    expect($toolResult['isError'])->toBeTrue()
        ->and($resourceResult['isError'])->toBeTrue()
        ->and($promptResult['isError'])->toBeTrue();

    // Identical structuredContent payload shape.
    foreach ([$toolResult, $resourceResult, $promptResult] as $result) {
        /** @var array<string, mixed> $structured */
        $structured = $result['structuredContent'];
        expect($structured['x402Version'] ?? null)->toBe(2)
            ->and($structured['error'] ?? null)->toBe('Payment required.')
            ->and($structured['accepts'] ?? null)->toHaveCount(1);
    }

    // Identical content[0] shape: type=text + JSON-stringified envelope body.
    foreach ([$toolResult, $resourceResult, $promptResult] as $result) {
        /** @var list<array<string, mixed>> $content */
        $content = $result['content'];
        expect($content)->toHaveCount(1);
        expect($content[0]['type'] ?? null)->toBe('text');

        /** @var string $text */
        $text = $content[0]['text'];
        $decoded = json_decode($text, associative: true);
        expect($decoded)->toBeArray();

        /** @var array<string, mixed> $decoded */
        expect($decoded['x402Version'] ?? null)->toBe(2);
    }
});
