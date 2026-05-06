<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Attributes\X402Price;

it('captures all four parameters', function (): void {
    $price = new X402Price(amount: '0.01', asset: 'USDC', network: 'base', payTo: '0xabc');

    expect($price->amount)->toBe('0.01')
        ->and($price->asset)->toBe('USDC')
        ->and($price->network)->toBe('base')
        ->and($price->payTo)->toBe('0xabc');
});

it('defaults asset to USDC and network to base', function (): void {
    $price = new X402Price(amount: '0.01');

    expect($price->asset)->toBe('USDC')
        ->and($price->network)->toBe('base')
        ->and($price->payTo)->toBeNull();
});

it('is reflectable as a class attribute', function (): void {
    $reflection = new ReflectionClass(new #[X402Price(amount: '0.05')] class {});

    $attrs = $reflection->getAttributes(X402Price::class);

    expect($attrs)->toHaveCount(1)
        ->and($attrs[0]->newInstance()->amount)
        ->toBe('0.05');
});
