<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use X402\Laravel\Mcp\X402McpServiceProvider;
use X402\Laravel\X402ServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [
            X402ServiceProvider::class,
            X402McpServiceProvider::class,
        ];
    }

    protected function defineEnvironment(mixed $app): void
    {
        if (! $app instanceof Application) {
            return;
        }

        $app->make(Repository::class)->set('x402.recipient', '0x000000000000000000000000000000000000beef');
        $app->make(Repository::class)->set('x402.wallet.private_key', '0x' . str_repeat('1', 64));
    }
}
