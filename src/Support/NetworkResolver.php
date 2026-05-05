<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Support;

final class NetworkResolver
{
    public static function toCaip2(string $slug): string
    {
        return match ($slug) {
            'base' => 'eip155:8453',
            'base-sepolia' => 'eip155:84532',
            'ethereum' => 'eip155:1',
            'polygon' => 'eip155:137',
            'arbitrum' => 'eip155:42161',
            default => $slug,
        };
    }
}
