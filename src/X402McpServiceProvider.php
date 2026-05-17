<?php

declare(strict_types=1);

namespace X402\Laravel\Mcp;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use X402\Laravel\Cache\LaravelPsr16Bridge;
use X402\Laravel\Mcp\Console\ListToolsCommand;
use X402\Laravel\Mcp\Server\Cache\PaidToolResponseCache;
use X402\Laravel\Mcp\Server\ChallengeFactory;
use X402\Laravel\Support\ConfigReader;

/**
 * Container bindings for the MCP-side of the x402 bridge. `X402CallTool`,
 * `X402ReadResource`, and `X402GetPrompt` are resolved through the Laravel
 * container when the host's Server class uses `WithX402Payment` (or wires
 * the method handlers manually); their constructor dependencies
 * (`FacilitatorClient`, `NonceStoreContract`, `Repository`) are bound by
 * `laravel-x402`.
 *
 * This provider also binds `PaidToolResponseCache` (the JSON-RPC
 * idempotent-paid-response cache) so a host that wants to wire it into a
 * custom Server can resolve a configured instance without redoing the
 * `LaravelPsr16Bridge` plumbing.
 */
final class X402McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChallengeFactory::class, static function (Application $app): ChallengeFactory {
            return new ChallengeFactory($app->make(ConfigRepository::class));
        });

        $this->app->singleton(PaidToolResponseCache::class, static function (Application $app): PaidToolResponseCache {
            $config = $app->make(ConfigRepository::class);
            $cacheFactory = $app->make(CacheFactory::class);

            $store = ConfigReader::stringOrNull($config, 'x402.response_cache.cache_store');
            $ttl = ConfigReader::int($config, 'x402.response_cache.ttl', 3600);

            // JSON-RPC-namespaced prefix so HTTP and MCP consumers don't
            // collide on a shared Redis store. Operators override under
            // `x402_mcp.response_cache.prefix` for strict parity with
            // the HTTP middleware's `x402:idem:`.
            $prefix = ConfigReader::string($config, 'x402_mcp.response_cache.prefix', PaidToolResponseCache::DEFAULT_PREFIX);

            $cache = new LaravelPsr16Bridge($cacheFactory->store($store));

            // Wire Laravel's logger so cache-write failures surface as
            // warnings (broken idempotency is operationally important;
            // silent degradation reopens the paid-but-no-response gap).
            $logger = $app->make(LoggerInterface::class);

            return new PaidToolResponseCache($cache, $ttl, $prefix, $logger);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListToolsCommand::class,
            ]);
        }
    }
}
