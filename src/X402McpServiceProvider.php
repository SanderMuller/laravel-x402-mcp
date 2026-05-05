<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Registrar;
use Psr\Log\LoggerInterface;
use X402\Laravel\Mcp\Server\MetaInjector;

final class X402McpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Best-effort sanity check at boot — surfaces tools that declared
        // #[X402Price] but forgot to `use HasX402Price;`. Only runs in
        // local/testing to avoid noise in production logs.
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        if (! $this->app->bound(Registrar::class)) {
            return;
        }

        try {
            /** @var Registrar $registrar */
            $registrar = $this->app->make(Registrar::class);
            $missing = MetaInjector::findUntraitedTools($registrar);

            if ($missing === []) {
                return;
            }

            /** @var LoggerInterface $logger */
            $logger = $this->app->make(LoggerInterface::class);
            foreach ($missing as $class) {
                $logger->warning(sprintf(
                    'x402: tool %s has #[X402Price] but does not use HasX402Price — agents will not see the price in tools/list.',
                    $class,
                ));
            }
        } catch (\Throwable) {
            // Never break boot.
        }
    }
}
