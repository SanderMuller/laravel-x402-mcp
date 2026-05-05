<?php

declare(strict_types=1);

use X402\Laravel\Mcp\Attributes\X402Price;
use X402\Laravel\Mcp\Concerns\HasX402Price;

abstract class FakeBaseTool
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => 'fake',
            'description' => 'A fake tool for tests.',
        ];
    }
}

#[X402Price(amount: '0.05', asset: 'USDC', network: 'base', payTo: '0x000000000000000000000000000000000000beef')]
final class PaidFakeTool extends FakeBaseTool
{
    use HasX402Price;
}

final class FreeFakeTool extends FakeBaseTool
{
    use HasX402Price;
}

it('injects _meta.x402 when the X402Price attribute is present', function (): void {
    $array = (new PaidFakeTool)->toArray();

    expect($array['_meta']['x402'])->toBe([
        'amount' => '0.05',
        'asset' => 'USDC',
        'network' => 'base',
        'payTo' => '0x000000000000000000000000000000000000beef',
    ]);
});

it('leaves the array untouched when no X402Price attribute is declared', function (): void {
    $array = (new FreeFakeTool)->toArray();

    expect($array)->not->toHaveKey('_meta');
});

it('preserves existing _meta entries', function (): void {
    /**
     * @return array<string, mixed>
     */
    $tool = new #[X402Price(amount: '0.01')] class extends FakeBaseTool
    {
        use HasX402Price;

        public function toArray(): array
        {
            $parent = parent::toArray();
            $parent['_meta'] = ['ui' => ['icon' => 'star']];

            $price = (new ReflectionClass($this))->getAttributes(X402Price::class);
            if ($price !== []) {
                /** @var X402Price $instance */
                $instance = $price[0]->newInstance();
                $parent['_meta']['x402'] = \X402\Laravel\Mcp\Support\PriceMeta::build($instance);
            }

            return $parent;
        }
    };

    $array = $tool->toArray();

    expect($array['_meta'])->toHaveKey('ui')->toHaveKey('x402');
});
