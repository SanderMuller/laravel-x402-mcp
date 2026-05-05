<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Support\PriceMeta;

it('builds the spec-shaped meta block', function (): void {
    $price = new X402Price(amount: '0.01', asset: 'USDC', network: 'base', payTo: '0xabc');

    expect(PriceMeta::build($price))->toBe([
        'amount' => '0.01',
        'asset' => 'USDC',
        'network' => 'base',
        'payTo' => '0xabc',
    ]);
});
