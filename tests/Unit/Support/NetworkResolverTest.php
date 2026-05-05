<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Support\NetworkResolver;

it('resolves known network slugs to CAIP-2', function (string $slug, string $caip2): void {
    expect(NetworkResolver::toCaip2($slug))->toBe($caip2);
})->with([
    ['base', 'eip155:8453'],
    ['base-sepolia', 'eip155:84532'],
    ['ethereum', 'eip155:1'],
    ['polygon', 'eip155:137'],
    ['arbitrum', 'eip155:42161'],
]);

it('passes through unknown values verbatim (CAIP-2 ids reach the wire untouched)', function (): void {
    expect(NetworkResolver::toCaip2('eip155:42220'))->toBe('eip155:42220');
});
