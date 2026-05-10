<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\Tool;
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

it('resolveFor returns the attribute on a Tool subclass', function (): void {
    $tool = new #[X402Price(amount: '0.10', asset: 'USDC', network: 'base')] class extends Tool {
        public function description(): string
        {
            return 'paid tool fixture';
        }

        public function handle(Request $request): Response
        {
            return Response::json(['ok' => true]);
        }
    };

    $price = X402Price::resolveFor($tool);

    expect($price)->toBeInstanceOf(X402Price::class)
        ->and($price?->amount)->toBe('0.10');
});

it('resolveFor returns the attribute on a Resource subclass — pins the Primitive widening', function (): void {
    $resource = new #[X402Price(amount: '0.25', asset: 'USDC', network: 'base-sepolia')] class extends Resource {
        protected string $uri = 'mcp://test/priced-resource';

        public function description(): string
        {
            return 'paid resource fixture';
        }

        public function handle(): Response
        {
            return Response::text('ok');
        }
    };

    $price = X402Price::resolveFor($resource);

    expect($price)->toBeInstanceOf(X402Price::class)
        ->and($price?->amount)->toBe('0.25')
        ->and($price?->network)->toBe('base-sepolia');
});

it('resolveFor returns the attribute on a Prompt subclass', function (): void {
    $prompt = new #[X402Price(amount: '0.50')] class extends Prompt {
        public function description(): string
        {
            return 'paid prompt fixture';
        }

        public function handle(): Response
        {
            return Response::text('ok');
        }
    };

    $price = X402Price::resolveFor($prompt);

    expect($price)->toBeInstanceOf(X402Price::class)
        ->and($price?->amount)->toBe('0.50');
});

it('resolveFor returns null when the primitive is not gated', function (): void {
    $tool = new class extends Tool {
        public function description(): string
        {
            return 'free tool fixture';
        }

        public function handle(Request $request): Response
        {
            return Response::json(['ok' => true]);
        }
    };

    expect(X402Price::resolveFor($tool))->toBeNull();
});

it('toMetaArray emits amount + asset + network and omits payTo when null', function (): void {
    $price = new X402Price(amount: '0.10', asset: 'USDC', network: 'base');

    expect($price->toMetaArray())->toBe([
        'amount' => '0.10',
        'asset' => 'USDC',
        'network' => 'base',
    ]);
});

it('toMetaArray includes payTo when overridden', function (): void {
    $price = new X402Price(amount: '5.00', asset: 'USDC', network: 'base', payTo: '0x000000000000000000000000000000000000beef');

    expect($price->toMetaArray())->toBe([
        'amount' => '5.00',
        'asset' => 'USDC',
        'network' => 'base',
        'payTo' => '0x000000000000000000000000000000000000beef',
    ]);
});
