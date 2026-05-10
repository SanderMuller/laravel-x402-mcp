<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Server\Cache\CacheScope;

it('builds tools/call scope with method, mcp://tool/{name}, and an args hash', function (): void {
    $scope = CacheScope::forToolCall('fetch-premium', ['user_id' => 42]);

    expect($scope->segments)->toHaveCount(3)
        ->and($scope->segments[0])->toBe('tools/call')
        ->and($scope->segments[1])->toBe('mcp://tool/fetch-premium')
        ->and($scope->segments[2])->toMatch('/^[a-f0-9]{64}$/');
});

it('builds resources/read scope with method and concrete URI verbatim — no args hash', function (): void {
    $scope = CacheScope::forResourceRead('mcp://x/users/1');

    expect($scope->segments)->toBe([
        'resources/read',
        'mcp://x/users/1',
    ]);
});

it('builds prompts/get scope with method, mcp://prompt/{name}, and an args hash', function (): void {
    $scope = CacheScope::forPromptGet('greet', ['locale' => 'en']);

    expect($scope->segments)->toHaveCount(3)
        ->and($scope->segments[0])->toBe('prompts/get')
        ->and($scope->segments[1])->toBe('mcp://prompt/greet');
});

it('produces the same args hash regardless of key order in the argument bag', function (): void {
    $a = CacheScope::forToolCall('foo', ['a' => 1, 'b' => 2, 'c' => 3]);
    $b = CacheScope::forToolCall('foo', ['c' => 3, 'a' => 1, 'b' => 2]);

    expect($a->segments[2])->toBe($b->segments[2]);
});

it('produces the same args hash for nested bags with reordered nested keys', function (): void {
    $a = CacheScope::forToolCall('foo', ['outer' => ['x' => 1, 'y' => 2]]);
    $b = CacheScope::forToolCall('foo', ['outer' => ['y' => 2, 'x' => 1]]);

    expect($a->segments[2])->toBe($b->segments[2]);
});

it('produces different args hashes for different argument values', function (): void {
    $a = CacheScope::forToolCall('foo', ['user_id' => 1]);
    $b = CacheScope::forToolCall('foo', ['user_id' => 2]);

    expect($a->segments[2])->not->toBe($b->segments[2]);
});

it('isolates tools/call from prompts/get even with the same name + args', function (): void {
    $tool = CacheScope::forToolCall('echo', ['message' => 'hi']);
    $prompt = CacheScope::forPromptGet('echo', ['message' => 'hi']);

    // Different method + different challenge URI prefix means the full
    // segment list differs, even when args hashes happen to match.
    expect($tool->segments)->not->toBe($prompt->segments)
        ->and($tool->segments[0])->toBe('tools/call')
        ->and($prompt->segments[0])->toBe('prompts/get');
});

it('isolates resources/read concrete URIs under the same priced template', function (): void {
    $u1 = CacheScope::forResourceRead('mcp://x/users/1');
    $u2 = CacheScope::forResourceRead('mcp://x/users/2');

    expect($u1->segments)->not->toBe($u2->segments);
});

it('produces distinct hashes for numeric-keyed JSON object arguments (regression: stringKeyed-filter aliasing)', function (): void {
    // `json_decode('{"1":"a"}', true)` produces `[1 => "a"]` with an integer
    // key — PHP coerces numeric-string JSON keys to ints. The pre-fix
    // helper filtered to string keys via `stringKeyed`, collapsing both
    // bags to `[]` and aliasing them under one hash. After the fix, the
    // raw decoded array reaches the canonicaliser and the two bags hash
    // distinctly.
    $a = CacheScope::forToolCall('foo', [1 => 'a']);
    $b = CacheScope::forToolCall('foo', [2 => 'b']);

    expect($a->segments[2])->not->toBe($b->segments[2]);
});

it('distinguishes integer 1 from float 1.0 in argument hashing (JSON_PRESERVE_ZERO_FRACTION)', function (): void {
    // Without `JSON_PRESERVE_ZERO_FRACTION`, both encode as `1` and alias.
    // With it, `1.0` encodes as `1.0` and `1` encodes as `1` — distinct
    // hashes.
    $intBag = CacheScope::forToolCall('foo', ['n' => 1]);
    $floatBag = CacheScope::forToolCall('foo', ['n' => 1.0]);

    expect($intBag->segments[2])->not->toBe($floatBag->segments[2]);
});

it('throws InvalidArgumentException when the argument bag cannot be canonicalised', function (): void {
    // A resource handle is non-JSON-encodable — `json_encode` throws via
    // JSON_THROW_ON_ERROR and we re-throw as InvalidArgumentException so
    // the caller sees a clear "you built a bag we can't fingerprint"
    // signal instead of a silently empty hash.
    $resource = fopen('php://memory', 'r');

    try {
        expect(fn (): CacheScope => CacheScope::forToolCall('foo', ['fp' => $resource]))
            ->toThrow(InvalidArgumentException::class, 'cannot canonicalise arguments');
    } finally {
        if (is_resource($resource)) {
            fclose($resource);
        }
    }
});
